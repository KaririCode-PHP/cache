<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats as DriverStatsContract;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise-grade Redis cache driver with comprehensive error handling and security.
 *
 * @see https://redis.io/documentation Redis Official Documentation
 * @see https://github.com/phpredis/phpredis PHPRedis Extension Documentation
 */
final class RedisDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    TopicAwareCacheDriver,
    CompareAndSwapAware
{
    private \Redis $redis;
    private string $keyPrefix;
    private string $tagPrefix;
    private string $topicPrefix;
    private LoggerInterface $logger;
    private bool $connected = false;
    private int $retryAttempts = 3;
    private float $retryDelay = 0.1; // 100ms

    /** @var array<string, mixed> */
    private array $connectionConfig = [];

    // Security limits
    private const MAX_KEY_LENGTH = 250;
    private const MAX_VALUE_SIZE = 512 * 1024 * 1024; // 512MB (Redis default)
    private const MAX_BATCH_SIZE = 1000;
    private const SCAN_BATCH_SIZE = 500;

    /** @var array<string, string> */
    private array $luaScripts = [];

    /**
     * @param array<string, mixed> $connectionConfig
     */
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
            $value = $this->executeWithRetry(function () use ($key) {
                return $this->redis->get($this->keyPrefix . $key);
            });

            if (false === $value) {
                return null;
            }

            if (is_string($value)) {
                return $this->unserialize($value);
            }

            return null;
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

            if ($result) {
                $this->logger->debug('Cache set successful', [
                    'key' => $key,
                    'size' => strlen($serialized),
                    'ttl' => $expiration ? $expiration->getTimestamp() - time() : null,
                ]);
            }

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
            $exists = $this->executeWithRetry(function () use ($key) {
                return $this->redis->exists($this->keyPrefix . $key);
            });

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

    /**
     * @param string[] $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return [];
        }

        $batches = array_chunk($keys, self::MAX_BATCH_SIZE);
        $results = [];

        foreach ($batches as $batch) {
            try {
                $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $batch);
                $rawValues = $this->executeWithRetry(fn () => $this->redis->mget($prefixedKeys));

                if (!is_array($rawValues)) {
                    $rawValues = [];
                }

                foreach ($batch as $index => $key) {
                    $value = $rawValues[$index] ?? false;
                    if (false !== $value && is_string($value)) {
                        $results[$key] = $this->unserialize($value);
                    } else {
                        $results[$key] = null;
                    }
                }
            } catch (\RedisException $e) {
                $this->handleRedisError($e, 'getMultiple', implode(',', $batch));
                foreach ($batch as $key) {
                    $results[$key] = null;
                }
            }
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
                    $fullKey = $this->keyPrefix . $key;
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

    public function increment(
        string $key,
        int $step = 1,
        int $initialValue = 0,
        \DateInterval|int|null $ttl = null
    ): int {
        $this->validateKey($key);
        $this->validateStep($step);

        $raw = $this->executeWithRetry(function () use ($key, $step, $initialValue, $ttl) {
            $fullKey = $this->keyPrefix . $key;

            if (null !== $ttl) {
                return $this->incrementWithTtl($fullKey, $step, $initialValue, $ttl);
            }

            if (!$this->redis->exists($fullKey)) {
                $this->redis->set($fullKey, $initialValue);
            }

            return $this->redis->incrBy($fullKey, $step);   // ← Redis sempre devolve inteiro
        });

        if (!is_int($raw)) {
            throw CacheException::operationFailed('increment', $key, new \UnexpectedValueException('Redis::incrBy should return int'));
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

                if ($ttl instanceof \DateInterval) {
                    $expiration = (new \DateTimeImmutable())->add($ttl);
                    $options['ex'] = max(1, $expiration->getTimestamp() - time());
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
            $expiration = null;
            if ($ttl instanceof \DateInterval) {
                $expiration = (new \DateTimeImmutable())->add($ttl);
            }

            return $this->set($key, $value, $expiration);
        }

        return false;
    }

    /**
     * @return list<string>
     */
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

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        if (empty($tags)) {
            return true;
        }

        try {
            return (bool) $this->executeWithRetry(function () use ($tags) {
                $keysToDelete = [];
                $tagKeys = [];

                foreach ($tags as $tag) {
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
                    $this->logger->info('Tags invalidated', ['tags' => $tags, 'keys_deleted' => count($keysToDelete)]);
                }

                return true;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'invalidateTags', implode(',', $tags));

            return false;
        }
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        try {
            $this->executeWithRetry(function () use ($key, $topics) {
                $fullKey = $this->keyPrefix . $key;
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($topics as $topic) {
                    $topicKey = $this->topicPrefix . $topic;
                    $pipe->sAdd($topicKey, $fullKey);
                }
                $pipe->exec();
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'subscribeTo', $key);
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);
        if (empty($topics)) {
            return true;
        }

        try {
            return (bool) $this->executeWithRetry(function () use ($topics) {
                $keysToDelete = [];
                $topicKeys = [];

                foreach ($topics as $topic) {
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
                    $this->logger->info('Topics invalidated', ['topics' => $topics, 'keys_deleted' => count($keysToDelete)]);
                }

                return true;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'invalidateTopics', implode(',', $topics));

            return false;
        }
    }

    public function tagItem(string $key, array $tags): void
    {
        $this->validateKey($key);
        $this->validateTags($tags);
        if (empty($tags)) {
            return;
        }

        try {
            $this->executeWithRetry(function () use ($key, $tags) {
                $fullKey = $this->keyPrefix . $key;
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($tags as $tag) {
                    $tagKey = $this->tagPrefix . $tag;
                    $pipe->sAdd($tagKey, $fullKey);
                }
                $pipe->exec();
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'tagItem', $key);
        }
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        try {
            $tagKeys = $this->executeWithRetry(fn () => $this->redis->keys($this->tagPrefix . '*'));
            if (!is_array($tagKeys)) {
                return [];
            }

            return array_map(fn ($key) => substr($key, strlen($this->tagPrefix)), $tagKeys);
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'getTags', '*');

            return [];
        }
    }

    public function getStats(): DriverStatsContract
    {
        try {
            $info = $this->redis->info();
            $dbsize = $this->redis->dbSize();

            return new DriverStats(
                driverName: 'Redis',
                memoryUsage: $info['used_memory'] ?? null,
                itemCount: is_int($dbsize) ? $dbsize : null,
                driverVersion: $info['redis_version'] ?? null,
                rawStats: $info
            );
        } catch (\RedisException $e) {
            return new DriverStats('Redis', null, null, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{status: string, ping?: string|bool, latency_ms?: float, connected?: bool, error?: string}
     */
    public function healthCheck(): array
    {
        try {
            $start = microtime(true);
            $result = $this->redis->ping();
            $latency = microtime(true) - $start;

            return [
                'status' => 'healthy',
                'ping' => $result,
                'latency_ms' => round($latency * 1000, 2),
                'connected' => $this->connected,
            ];
        } catch (\RedisException $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connected' => false,
            ];
        }
    }

    private function validateKey(string $key): void
    {
        if ('' === $key) {
            throw InvalidArgumentException::emptyKey();
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
    }

    /**
     * @param string[] $keys
     */
    private function validateKeys(array $keys): void
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Too many keys: ' . count($keys));
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        $serialized = $this->serialize($value);
        if (strlen($serialized) > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Value too large: ' . strlen($serialized) . ' bytes');
        }
    }

    private function validateMultipleValues(array $values): void
    {
        if (count($values) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Too many values: ' . count($values));
        }
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
            $this->validateValue($value);
        }
    }

    /**
     * @param string[] $tags
     */
    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || '' === $tag) {
                throw InvalidArgumentException::invalidTag($tag);
            }
        }
    }

    /**
     * @param string[] $topics
     */
    private function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic) || '' === $topic) {
                throw InvalidArgumentException::invalidTopic($topic);
            }
        }
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidKeyFormat('Step cannot be zero');
        }
    }

    private function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed('unknown', $e);
        }
    }

    private function unserialize(string $data): mixed
    {
        try {
            return unserialize($data);
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('unknown', $e);
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
                    $this->logger->warning('Redis operation failed, retrying', [
                        'attempt' => $attempt, 'error' => $e->getMessage(),
                    ]);
                    usleep((int) ($this->retryDelay * 1000000 * $attempt));
                }
            }
        }

        $this->logger->error('Redis operation failed after all retries', [
            'attempts' => $this->retryAttempts, 'error' => $lastException?->getMessage(),
        ]);

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
            /** @var string $host */
            $host = $this->connectionConfig['host'] ?? '127.0.0.1';
            /** @var int $port */
            $port = $this->connectionConfig['port'] ?? 6379;
            /** @var float $timeout */
            $timeout = $this->connectionConfig['timeout'] ?? 2.0;
            /** @var string|null $persistent_id */
            $persistent_id = $this->connectionConfig['persistent_id'] ?? null;
            /** @var int $retry_interval */
            $retry_interval = $this->connectionConfig['retry_interval'] ?? 0;
            /** @var float $read_timeout */
            $read_timeout = $this->connectionConfig['read_timeout'] ?? 0.0;

            if ($this->connectionConfig['persistent'] ?? false) {
                $this->redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout);
            } else {
                $this->redis->connect($host, $port, $timeout, null, $retry_interval, $read_timeout);
            }

            $this->connected = true;
            $this->logger->info('Redis reconnection successful');
        } catch (\RedisException $e) {
            $this->connected = false;
            $this->logger->error('Redis reconnection failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function handleRedisError(\RedisException $e, string $operation, string $key): void
    {
        $this->connected = false;
        $this->logger->error('Redis operation error', [
            'operation' => $operation, 'key' => $key, 'error' => $e->getMessage(),
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
        try {
            $tagKeys = $this->redis->keys($this->tagPrefix . '*');
            if (!empty($tagKeys) && is_array($tagKeys)) {
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($tagKeys as $tagKey) {
                    $pipe->sRem($tagKey, $fullKey);
                }
                $pipe->exec();
            }

            $topicKeys = $this->redis->keys($this->topicPrefix . '*');
            if (!empty($topicKeys) && is_array($topicKeys)) {
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($topicKeys as $topicKey) {
                    $pipe->sRem($topicKey, $fullKey);
                }
                $pipe->exec();
            }
        } catch (\RedisException $e) {
            $this->logger->warning('Failed to remove from indexes', [
                'key' => $fullKey, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param int|\DateInterval|null $ttl
     */
    private function incrementWithTtl(string $fullKey, int $step, int $initialValue, $ttl): int
    {
        $ttlSeconds = 0;
        if (is_int($ttl)) {
            $ttlSeconds = $ttl;
        } elseif ($ttl instanceof \DateInterval) {
            $expiration = (new \DateTimeImmutable())->add($ttl);
            $ttlSeconds = $expiration->getTimestamp() - time();
        }

        $ttlSeconds = max(1, $ttlSeconds);
        $script = $this->luaScripts['incrementWithTtl'];

        /** @var int|bool $result */
        $result = $this->redis->eval($script, [$fullKey, $step, $initialValue, $ttlSeconds], 1);

        return (int) $result;
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
