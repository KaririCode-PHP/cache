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
 * Handles batch operations for Redis.
 * Single Responsibility: Multi-key operations (getMultiple, setMultiple, deleteMultiple).
 */
final class RedisBatchOperations
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

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return [];
        }

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keys),
            $this->connectionManager,
            'getMultiple',
            implode(',', $keys)
        );
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        if (empty($values)) {
            return true;
        }

        $this->validateMultipleValues($values);

        if ($expiration && $expiration->getTimestamp() <= time()) {
            throw InvalidArgumentException::invalidTtl('Expiration time is in the past');
        }

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($values, $expiration),
            $this->connectionManager,
            'setMultiple',
            implode(',', array_keys($values))
        );
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return true;
        }

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteMultiple($keys),
            $this->connectionManager,
            'deleteMultiple',
            implode(',', $keys)
        );
    }

    private function performGetMultiple(array $keys): array
    {
        $prefixedKeys = array_map(fn ($k) => $this->config->keyPrefix . $k, $keys);

        try {
            $rawValues = $this->redis->mget($prefixedKeys);

            if (!is_array($rawValues)) {
                return array_fill_keys($keys, null);
            }

            $results = [];
            foreach ($keys as $index => $key) {
                $value = $rawValues[$index] ?? false;

                if (false === $value || !is_string($value)) {
                    $results[$key] = null;
                    continue;
                }

                try {
                    $results[$key] = $this->unserialize($value);
                } catch (\Throwable $e) {
                    // Log error but don't fail the entire operation
                    $results[$key] = null;
                }
            }

            return $results;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('mget', implode(',', $keys), $e);
        }
    }

    private function performSetMultiple(array $values, ?\DateTimeInterface $expiration): bool
    {
        try {
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            foreach ($values as $key => $value) {
                $fullKey = $this->config->keyPrefix . (string) $key;

                try {
                    $serialized = $this->serialize($value);
                } catch (\Throwable $e) {
                    throw SerializationException::serializationFailed($value, $e);
                }

                if ($expiration) {
                    $ttl = max(1, $expiration->getTimestamp() - time());
                    $pipe->setex($fullKey, $ttl, $serialized);
                } else {
                    $pipe->set($fullKey, $serialized);
                }
            }

            $results = $pipe->exec();

            return is_array($results) && !in_array(false, $results, true);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('setMultiple', implode(',', array_keys($values)), $e);
        }
    }

    private function performDeleteMultiple(array $keys): bool
    {
        $prefixedKeys = array_map(fn ($k) => $this->config->keyPrefix . $k, $keys);

        try {
            $deleted = $this->redis->del($prefixedKeys);

            return $deleted > 0;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('deleteMultiple', implode(',', $keys), $e);
        }
    }

    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            try {
                $this->validateKey((string) $key);
                $this->validateValue($value);
            } catch (\Throwable $e) {
                if ($e instanceof InvalidArgumentException) {
                    throw $e; // Re-throw as is
                }
                throw InvalidArgumentException::invalidValue($value, "Invalid value for key '{$key}': " . $e->getMessage());
            }
        }
    }
}
