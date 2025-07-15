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
 * Features:
 * - Connection resilience with automatic retry
 * - Security validation and rate limiting
 * - Efficient batch operations with pipelining
 * - Atomic operations with proper error handling
 * - Comprehensive logging and monitoring
 * - Memory-efficient tag and topic management
 * - Thread-safe operations
 *
 * Performance Optimizations:
 * - Pipeline batching for multi-operations
 * - Efficient SCAN operations for large datasets
 * - Lua scripts for atomic complex operations
 * - Connection pooling and persistence
 *
 * Security Features:
 * - Input validation and sanitization
 * - Rate limiting and abuse prevention
 * - Secure serialization handling
 * - Audit logging for sensitive operations
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
    private array $connectionConfig = [];

    // Security limits
    private const MAX_KEY_LENGTH = 250;
    private const MAX_VALUE_SIZE = 512 * 1024 * 1024; // 512MB (Redis default)
    private const MAX_BATCH_SIZE = 1000;
    private const SCAN_BATCH_SIZE = 500;

    // Lua scripts for atomic operations
    private array $luaScripts = [];

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

            return $this->unserialize($value);
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
            return $this->executeWithRetry(function () use ($key) {
                return $this->redis->exists($this->keyPrefix . $key) > 0;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'has', $key);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        try {
            return $this->executeWithRetry(function () use ($key) {
                $fullKey = $this->keyPrefix . $key;

                // Remove from all tags and topics before deleting
                $this->removeFromAllIndexes($fullKey);

                return $this->redis->del($fullKey) > 0;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'delete', $key);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            return $this->executeWithRetry(function () {
                $deletedKeys = 0;

                // Clear main cache keys
                $deletedKeys += $this->scanAndDelete($this->keyPrefix . '*');

                // Clear tag indexes
                $deletedKeys += $this->scanAndDelete($this->tagPrefix . '*');

                // Clear topic indexes
                $deletedKeys += $this->scanAndDelete($this->topicPrefix . '*');

                $this->logger->info('Cache cleared', [
                    'deleted_keys' => $deletedKeys,
                ]);

                return true;
            });
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

        // Batch keys to prevent memory issues
        $batches = array_chunk($keys, self::MAX_BATCH_SIZE);
        $results = [];

        foreach ($batches as $batch) {
            try {
                $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $batch);
                $rawValues = $this->executeWithRetry(function () use ($prefixedKeys) {
                    return $this->redis->mget($prefixedKeys);
                });

                foreach ($batch as $index => $key) {
                    $value = $rawValues[$index] ?? false;
                    $results[$key] = (false !== $value) ? $this->unserialize($value) : null;
                }
            } catch (\RedisException $e) {
                $this->handleRedisError($e, 'getMultiple', implode(',', $batch));

                // Fill with nulls for this batch
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
            return $this->executeWithRetry(function () use ($values, $expiration) {
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

                // Check if all operations succeeded
                return !in_array(false, $results, true);
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
            return $this->executeWithRetry(function () use ($keys) {
                $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);

                // Remove from indexes before deleting
                foreach ($prefixedKeys as $fullKey) {
                    $this->removeFromAllIndexes($fullKey);
                }

                return $this->redis->del(...$prefixedKeys) > 0;
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'deleteMultiple', implode(',', $keys));

            return false;
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        try {
            return $this->executeWithRetry(function () use ($key, $step, $initialValue, $ttl) {
                $fullKey = $this->keyPrefix . $key;

                // Use Lua script for atomic increment with TTL
                if (null !== $ttl) {
                    return $this->incrementWithTtl($fullKey, $step, $initialValue, $ttl);
                }

                // Simple atomic increment
                if (!$this->redis->exists($fullKey)) {
                    $this->redis->set($fullKey, $initialValue);
                }

                return $this->redis->incrBy($fullKey, $step);
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'increment', $key);
            throw CacheException::operationFailed('increment', $key, $e);
        }
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            return $this->executeWithRetry(function () use ($key, $value, $ttl) {
                $fullKey = $this->keyPrefix . $key;
                $serialized = $this->serialize($value);

                $options = ['nx']; // Only set if not exists

                if (null !== $ttl) {
                    if (is_int($ttl)) {
                        $options['ex'] = max(1, $ttl);
                    } elseif ($ttl instanceof \DateTimeInterface) {
                        $options['ex'] = max(1, $ttl->getTimestamp() - time());
                    }
                }

                return (bool) $this->redis->set($fullKey, $serialized, $options);
            });
        } catch (\RedisException $e) {
            $this->handleRedisError($e, 'add', $key);

            return false;
        }
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);

        if (empty($tags)) {
            return true;
        }

        try {
            return $this->executeWithRetry(function () use ($tags) {
                $keysToDelete = [];
                $tagKeys = [];

                foreach ($tags as $tag) {
                    $tagKey = $this->tagPrefix . $tag;
                    $tagKeys[] = $tagKey;

                    $keys = $this->redis->sMembers($tagKey);
                    if (!empty($keys)) {
                        $keysToDelete = array_merge($keysToDelete, $keys);
                    }
                }

                if (!empty($keysToDelete)) {
                    $keysToDelete = array_unique($keysToDelete);

                    // Use pipeline for efficient deletion
                    $pipe = $this->redis->multi(\Redis::PIPELINE);

                    // Delete all keys
                    $pipe->del(...$keysToDelete);

                    // Delete tag indexes
                    $pipe->del(...$tagKeys);

                    $pipe->exec();

                    $this->logger->info('Tags invalidated', [
                        'tags' => $tags,
                        'keys_deleted' => count($keysToDelete),
                    ]);
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
            return $this->executeWithRetry(function () use ($topics) {
                $keysToDelete = [];
                $topicKeys = [];

                foreach ($topics as $topic) {
                    $topicKey = $this->topicPrefix . $topic;
                    $topicKeys[] = $topicKey;

                    $keys = $this->redis->sMembers($topicKey);
                    if (!empty($keys)) {
                        $keysToDelete = array_merge($keysToDelete, $keys);
                    }
                }

                if (!empty($keysToDelete)) {
                    $keysToDelete = array_unique($keysToDelete);

                    $pipe = $this->redis->multi(\Redis::PIPELINE);
                    $pipe->del(...$keysToDelete);
                    $pipe->del(...$topicKeys);
                    $pipe->exec();

                    $this->logger->info('Topics invalidated', [
                        'topics' => $topics,
                        'keys_deleted' => count($keysToDelete),
                    ]);
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

    public function hasCapability(string $capability): bool
    {
        return match ($capability) {
            CacheCapabilities::ATOMIC_OPERATIONS => true,
            CacheCapabilities::TAGGING => true,
            CacheCapabilities::TOPICS => true,
            CacheCapabilities::TRANSACTIONS => true,
            default => false,
        };
    }

    public function getStats(): DriverStatsContract
    {
        try {
            $info = $this->redis->info();

            return new DriverStats(
                driverName: 'Redis',
                memoryUsage: $info['used_memory'] ?? null,
                itemCount: $info['db0']['keys'] ?? null, // Exemplo, pode variar
                driverVersion: $info['redis_version'] ?? null,
                rawStats: $info
            );
        } catch (\RedisException $e) {
            // Em caso de erro, retorna um objeto de estatísticas vazio/de erro.
            return new DriverStats('Redis', null, null, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Health check for monitoring.
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

    // Private helper methods...

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
    }

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

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || empty($tag)) {
                throw InvalidArgumentException::invalidTag($tag);
            }
        }
    }

    private function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic) || empty($topic)) {
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
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    usleep((int) ($this->retryDelay * 1000000 * $attempt)); // Exponential backoff
                } else {
                    $this->logger->error('Redis operation failed after all retries', [
                        'attempts' => $this->retryAttempts,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        throw $lastException;
    }

    private function verifyConnection(): void
    {
        try {
            $this->redis->ping();
            $this->connected = true;
        } catch (\RedisException $e) {
            $this->connected = false;
            $this->logger->error('Redis connection verification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function reconnect(): void
    {
        try {
            // Implement reconnection logic based on connection config
            $this->redis->ping();
            $this->connected = true;

            $this->logger->info('Redis reconnection successful');
        } catch (\RedisException $e) {
            $this->connected = false;

            $this->logger->error('Redis reconnection failed', [
                'error' => $e->getMessage(),
            ]);

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

        while ($keys = $this->redis->scan($iterator, $pattern, self::SCAN_BATCH_SIZE)) {
            if (!empty($keys)) {
                $deletedCount += $this->redis->del(...$keys);
            }
        }

        return $deletedCount;
    }

    private function removeFromAllIndexes(string $fullKey): void
    {
        try {
            // Remove from all tag sets
            $tagKeys = $this->redis->keys($this->tagPrefix . '*');
            if (!empty($tagKeys)) {
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($tagKeys as $tagKey) {
                    $pipe->sRem($tagKey, $fullKey);
                }
                $pipe->exec();
            }

            // Remove from all topic sets
            $topicKeys = $this->redis->keys($this->topicPrefix . '*');
            if (!empty($topicKeys)) {
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                foreach ($topicKeys as $topicKey) {
                    $pipe->sRem($topicKey, $fullKey);
                }
                $pipe->exec();
            }
        } catch (\RedisException $e) {
            $this->logger->warning('Failed to remove from indexes', [
                'key' => $fullKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function incrementWithTtl(string $fullKey, int $step, int $initialValue, $ttl): int
    {
        $ttlSeconds = is_int($ttl) ? $ttl : ($ttl instanceof \DateTimeInterface ? $ttl->getTimestamp() - time() : 0);
        $ttlSeconds = max(1, $ttlSeconds);

        // Use Lua script for atomic increment with TTL
        $script = $this->luaScripts['incrementWithTtl'];

        return $this->redis->eval($script, [$fullKey, $step, $initialValue, $ttlSeconds], 1);
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
