<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Exception\SerializationException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Handles basic CRUD operations for Redis.
 * Single Responsibility: Basic cache operations (get, set, has, delete, clear).
 */
final class RedisCrudOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager
    ) {
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGet($key),
            $this->connectionManager,
            'get',
            $key
        );
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $expiration),
            $this->connectionManager,
            'set',
            $key
        );
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->redis->exists($this->config->keyPrefix . $key) > 0,
            $this->connectionManager,
            'has',
            $key
        );
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->redis->del($this->config->keyPrefix . $key) > 0,
            $this->connectionManager,
            'delete',
            $key
        );
    }

    public function clear(): bool
    {
        return $this->errorHandler->executeWithRetry(
            fn () => $this->performClear(),
            $this->connectionManager,
            'clear',
            'all'
        );
    }

    private function performGet(string $key): mixed
    {
        $value = $this->redis->get($this->config->keyPrefix . $key);

        if (false === $value) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            return $this->unserialize($value);
        } catch (\Throwable $e) {
            // ✅ CORRIGIDO: Usando método estático da exceção
            throw SerializationException::deserializationFailed($value, $e);
        }
    }

    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        try {
            $serialized = $this->serialize($value);
        } catch (\Throwable $e) {
            // ✅ CORRIGIDO: Usando método estático da exceção
            throw SerializationException::serializationFailed($value, $e);
        }

        $fullKey = $this->config->keyPrefix . $key;

        if ($expiration) {
            $ttl = max(1, $expiration->getTimestamp() - time());
            if ($ttl <= 0) {
                // ✅ CORRIGIDO: Usando método estático da exceção
                throw InvalidArgumentException::invalidTtl($ttl);
            }

            return (bool) $this->redis->setex($fullKey, $ttl, $serialized);
        }

        return (bool) $this->redis->set($fullKey, $serialized);
    }

    private function performClear(): bool
    {
        $deletedCount = 0;
        $patterns = [
            $this->config->keyPrefix . '*',
            $this->config->tagPrefix . '*',
            $this->config->topicPrefix . '*',
        ];

        foreach ($patterns as $pattern) {
            $deletedCount += $this->scanAndDelete($pattern);
        }

        return true;
    }

    private function scanAndDelete(string $pattern): int
    {
        $deletedCount = 0;
        $iterator = null;

        try {
            $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            while (($keys = $this->redis->scan($iterator, $pattern, $this->config->scanBatchSize)) !== false) {
                if (is_array($keys) && !empty($keys)) {
                    $deletedCount += $this->redis->del($keys);
                }
            }
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('scanAndDelete', $pattern, $e);
        }

        return $deletedCount;
    }
}
