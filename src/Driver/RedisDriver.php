<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationCalculatorTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlCalculatorTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Cache\ValueObject\Topic;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedisDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    TopicAwareCacheDriver,
    CompareAndSwapAware
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TopicValidationTrait;
    use SerializationTrait;
    use TtlCalculatorTrait;
    use ExpirationCalculatorTrait;

    private \Redis $redis;
    private string $keyPrefix;
    private string $tagPrefix;
    private string $topicPrefix;
    private LoggerInterface $logger;
    private bool $connected = false;
    private int $retryAttempts = 3;
    private float $retryDelay = 0.1;

    /** @var array<string, mixed> */
    private array $connectionConfig = [];

    /** @var array<string, string> */
    private array $luaScripts = [];

    private const SCAN_BATCH_SIZE = 500;

    public function __construct(
        \Redis $redis,
        string $keyPrefix = 'cache:',
        string $tagPrefix = 'tag:',
        string $topicPrefix = 'topic:',
        ?LoggerInterface $logger = null,
        array $connectionConfig = []
    ) {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->tagPrefix = $tagPrefix;
        $this->topicPrefix = $topicPrefix;
        $this->logger = $logger ?? new NullLogger();
        $this->connectionConfig = $connectionConfig;

        $this->initializeLuaScripts();
        $this->verifyConnection();
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);

        try {
            $value = $this->executeWithRetry(fn () => $this->redis->get($this->keyPrefix . $key));

            if (false === $value) {
                return null;
            }

            return is_string($value) ? $this->unserialize($value) : null;
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'get', $key);

            return null;
        }
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            $serialized = $this->serialize($value);
            $fullKey = $this->keyPrefix . $key;

            $result = $this->executeWithRetry(function () use ($fullKey, $serialized, $expiration) {
                if ($expiration) {
                    $ttl = max(1, $expiration->getTimestamp() - time());

                    return $this->redis->setex($fullKey, $ttl, $serialized);
                }

                return $this->redis->set($fullKey, $serialized);
            });

            return (bool) $result;
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'set', $key);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        try {
            $exists = $this->executeWithRetry(fn () => $this->redis->exists($this->keyPrefix . $key));

            return $exists > 0;
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'has', $key);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        try {
            $deleted = $this->executeWithRetry(function () use ($key) {
                $fullKey = $this->keyPrefix . $key;
                $this->removeFromAllIndexes($fullKey);

                return $this->redis->del($fullKey);
            });

            return $deleted > 0;
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'delete', $key);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $result = $this->executeWithRetry(function () {
                $deletedKeys = 0;
                $deletedKeys += $this->scanAndDelete($this->keyPrefix . '*');
                $deletedKeys += $this->scanAndDelete($this->tagPrefix . '*');
                $deletedKeys += $this->scanAndDelete($this->topicPrefix . '*');
                $this->logger->info('Cache cleared', ['deleted_keys' => $deletedKeys]);

                return true;
            });

            return (bool) $result;
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'clear', 'all');

            return false;
        }
    }

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return [];
        }

        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $results = [];

        try {
            $rawValues = $this->executeWithRetry(fn () => $this->redis->mget($prefixedKeys));
            if (!is_array($rawValues)) {
                return array_fill_keys($keys, null);
            }

            foreach ($keys as $index => $key) {
                $value = $rawValues[$index] ?? false;
                $results[$key] = (false !== $value && is_string($value)) ? $this->unserialize($value) : null;
            }
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'getMultiple', implode(',', $keys));

            return array_fill_keys($keys, null);
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);
        if (empty($values)) {
            return true;
        }

        try {
            return (bool) $this->executeWithRetry(function () use ($values, $expiration) {
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                $ttl = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

                foreach ($values as $key => $value) {
                    $fullKey = $this->keyPrefix . (string) $key;
                    $serializedValue = $this->serialize($value);
                    if ($ttl > 0) {
                        $pipe->setex($fullKey, $ttl, $serializedValue);
                    } else {
                        $pipe->set($fullKey, $serializedValue);
                    }
                }
                $results = $pipe->exec();

                return is_array($results) && !in_array(false, $results, true);
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'setMultiple', 'batch');

            return false;
        }
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return true;
        }

        try {
            $deleted = $this->executeWithRetry(function () use ($keys) {
                $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
                foreach ($prefixedKeys as $fullKey) {
                    $this->removeFromAllIndexes($fullKey);
                }

                return $this->redis->del($prefixedKeys);
            });

            return $deleted > 0;
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'deleteMultiple', implode(',', $keys));

            return false;
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $raw = $this->executeWithRetry(function () use ($key, $step, $initialValue, $ttl) {
            $fullKey = $this->keyPrefix . $key;
            $ttlInSeconds = $this->calculateTtl($ttl);

            if ($ttlInSeconds > 0) {
                $script = $this->luaScripts['incrementWithTtl'];

                return $this->redis->eval($script, [$fullKey, $step, $initialValue, $ttlInSeconds], 1);
            }

            if (!$this->redis->exists($fullKey)) {
                $this->redis->set($fullKey, $initialValue);
            }

            return $this->redis->incrBy($fullKey, $step);
        });

        if (!is_int($raw)) {
            throw CacheException::operationFailed('increment', $key, new \UnexpectedValueException('Redis command should return an integer'));
        }

        return $raw;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            return (bool) $this->executeWithRetry(function () use ($key, $value, $ttl) {
                $fullKey = $this->keyPrefix . $key;
                $serialized = $this->serialize($value);
                $options = ['nx'];
                $ttlInSeconds = $this->calculateTtl($ttl);
                if ($ttlInSeconds > 0) {
                    $options['ex'] = $ttlInSeconds;
                }

                return $this->redis->set($fullKey, $serialized, $options);
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'add', $key);

            return false;
        }
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->logger->warning('compareAndSwap on RedisDriver is not fully atomic without a Lua script.');

        $current = $this->get($key);
        if ($current === $expected) {
            $expiration = $this->calculateExpiration($ttl);

            return $this->set($key, $value, $expiration);
        }

        return false;
    }

    public function getCapabilities(): array
    {
        $capabilities = [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
            CacheCapabilities::TOPICS,
        ];
        if (method_exists($this->redis, 'multi')) {
            $capabilities[] = CacheCapabilities::TRANSACTIONS;
        }

        return $capabilities;
    }

    /** @param Tag[] $tags */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        if (empty($tags)) {
            return true;
        }
        $tagStrings = array_map('strval', $tags);

        try {
            return (bool) $this->executeWithRetry(function () use ($tagStrings) {
                $keysToDelete = [];
                $tagKeys = [];
                foreach ($tagStrings as $tag) {
                    $tagKey = $this->tagPrefix . $tag;
                    $tagKeys[] = $tagKey;
                    $keys = $this->redis->sMembers($tagKey);
                    if (is_array($keys) && !empty($keys)) {
                        $keysToDelete = array_merge($keysToDelete, $keys);
                    }
                }
                if (!empty($keysToDelete)) {
                    $keysToDelete = array_unique($keysToDelete);
                    $pipe = $this->redis->multi(\Redis::PIPELINE);
                    $pipe->del($keysToDelete);
                    $pipe->del($tagKeys);
                    $pipe->exec();
                }

                return true;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'invalidateTags', implode(',', $tagStrings));

            return false;
        }
    }

    /** @param Topic[] $topics */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);
        $topicStrings = array_map('strval', $topics);

        try {
            $this->executeWithRetry(function () use ($key, $topicStrings) {
                $fullKey = $this->keyPrefix . $key;
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($topicStrings as $topic) {
                    $topicKey = $this->topicPrefix . $topic;
                    $pipe->sAdd($topicKey, $fullKey);
                }
                $pipe->exec();
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'subscribeTo', $key);
        }
    }

    /** @param Topic[] $topics */
    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);
        if (empty($topics)) {
            return true;
        }
        $topicStrings = array_map('strval', $topics);

        try {
            return (bool) $this->executeWithRetry(function () use ($topicStrings) {
                $keysToDelete = [];
                $topicKeys = [];
                foreach ($topicStrings as $topic) {
                    $topicKey = $this->topicPrefix . $topic;
                    $topicKeys[] = $topicKey;
                    $keys = $this->redis->sMembers($topicKey);
                    if (is_array($keys) && !empty($keys)) {
                        $keysToDelete = array_merge($keysToDelete, $keys);
                    }
                }
                if (!empty($keysToDelete)) {
                    $keysToDelete = array_unique($keysToDelete);
                    $pipe = $this->redis->multi(\Redis::PIPELINE);
                    $pipe->del($keysToDelete);
                    $pipe->del($topicKeys);
                    $pipe->exec();
                }

                return true;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'invalidateTopics', implode(',', $topicStrings));

            return false;
        }
    }

    public function getTags(): array
    {
        try {
            $tagKeys = $this->executeWithRetry(fn () => $this->redis->keys($this->tagPrefix . '*'));
            if (!is_array($tagKeys)) {
                return [];
            }

            return array_map(fn ($key) => str_replace($this->tagPrefix, '', $key), $tagKeys);
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'getTags', '*');

            return [];
        }
    }

    public function getStats(): DriverStats
    {
        try {
            $info = $this->redis->info();
            $dbsize = $this->redis->dbSize();

            return new MetricsSnapshot(
                'Redis',
                $info['used_memory'] ?? null,
                is_int($dbsize) ? $dbsize : null,
                $info['redis_version'] ?? null,
                $info
            );
        } catch (\RedisException $e) {
            return new MetricsSnapshot(
                'Redis',
                null,
                null,
                null,
                ['error' => $e->getMessage()]
            );
        }
    }

    private function executeWithRetry(callable $operation): mixed
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= $this->retryAttempts; ++$attempt) {
            try {
                if (!$this->connected) {
                    $this->reconnect();
                }

                return $operation();
            } catch (\RedisException $e) {
                $lastException = $e;
                $this->connected = false;
                if ($attempt < $this->retryAttempts) {
                    usleep((int) ($this->retryDelay * 1000000 * $attempt));
                }
            }
        }
        throw CacheException::operationFailed('retryable operation', 'unknown', $lastException);
    }

    private function verifyConnection(): void
    {
        try {
            $this->redis->ping();
            $this->connected = true;
        } catch (\RedisException $e) {
            $this->connected = false;
            $this->logger->error('Redis connection verification failed', ['error' => $e->getMessage()]);
        }
    }

    private function reconnect(): void
    {
        try {
            $host_mixed = $this->connectionConfig['host'] ?? '127.0.0.1';
            $port_mixed = $this->connectionConfig['port'] ?? 6379;
            $timeout_mixed = $this->connectionConfig['timeout'] ?? 2.0;
            $persistent_id_mixed = $this->connectionConfig['persistent_id'] ?? null;
            $retry_interval_mixed = $this->connectionConfig['retry_interval'] ?? 0;
            $read_timeout_mixed = $this->connectionConfig['read_timeout'] ?? 0.0;

            $host = is_string($host_mixed) ? $host_mixed : '127.0.0.1';
            $port = is_int($port_mixed) ? $port_mixed : 6379;
            $timeout = is_float($timeout_mixed) || is_int($timeout_mixed) ? (float) $timeout_mixed : 2.0;
            $persistent_id = is_string($persistent_id_mixed) ? $persistent_id_mixed : null;
            $retry_interval = is_int($retry_interval_mixed) ? $retry_interval_mixed : 0;
            $read_timeout = is_float($read_timeout_mixed) || is_int($read_timeout_mixed) ? (float) $read_timeout_mixed : 0.0;

            if ($this->connectionConfig['persistent'] ?? false) {
                $this->redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout);
            } else {
                $this->redis->connect($host, $port, $timeout, null, $retry_interval, $read_timeout);
            }
            $this->connected = true;
        } catch (\RedisException $e) {
            $this->connected = false;
            throw $e;
        }
    }

    private function handleRedisError(\RedisException $e, string $operation, string $key): void
    {
        $this->connected = false;
        $this->logger->error('Redis operation error', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
        ]);
    }

    private function scanAndDelete(string $pattern): int
    {
        $deletedCount = 0;
        $iterator = null;
        while (($keys = $this->redis->scan($iterator, $pattern, self::SCAN_BATCH_SIZE)) !== false) {
            if (is_array($keys) && !empty($keys)) {
                $deletedCount += $this->redis->del($keys);
            }
        }

        return $deletedCount;
    }

    private function removeFromAllIndexes(string $fullKey): void
    {
        // This can be slow with many tags/topics. In a high-performance scenario,
        // this logic would be handled differently (e.g., Lua scripts).
    }

    private function initializeLuaScripts(): void
    {
        $this->luaScripts['incrementWithTtl'] = '
            local key = KEYS[1]
            local step = tonumber(ARGV[1])
            local initial = tonumber(ARGV[2])
            local ttl = tonumber(ARGV[3])
            
            local current = redis.call("GET", key)
            if current == false then
                current = initial
            else
                current = tonumber(current)
            end
            
            current = current + step
            redis.call("SET", key, current, "EX", ttl)
            return current
        ';
    }
}
