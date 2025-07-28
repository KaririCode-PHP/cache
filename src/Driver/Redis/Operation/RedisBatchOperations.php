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
 * Handles batch operations for Redis (multi-key operations).
 * SRP: This class is only responsible for multi-key operations like mget, mset, and del.
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

    /**
     * @param array<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return [];
        }

        return (array) $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keys),
            $this->connectionManager,
            'getMultiple',
            implode(',', $keys)
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        if (empty($values)) {
            return true;
        }

        $this->validateMultipleValues($values);

        if ($expiration && $expiration->getTimestamp() <= time()) {
            throw InvalidArgumentException::invalidTtl('Expiration time is in the past');
        }

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($values, $expiration),
            $this->connectionManager,
            'setMultiple',
            implode(',', array_keys($values))
        );
    }

    /**
     * @param array<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return true;
        }

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteMultiple($keys),
            $this->connectionManager,
            'deleteMultiple',
            implode(',', $keys)
        );
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string, mixed>
     */
    private function performGetMultiple(array $keys): array
    {
        $prefixedKeys = array_map(fn ($k) => $this->config->keyPrefix . $k, $keys);

        try {
            /** @var list<string|false>|false $rawValues */
            $rawValues = $this->redis->mget($prefixedKeys);

            if (!is_array($rawValues)) {
                return array_fill_keys($keys, null);
            }

            $results = [];
            foreach ($keys as $index => $key) {
                $value = $rawValues[$index] ?? false;

                if (false === $value) {
                    $results[$key] = null;
                    continue;
                }

                try {
                    $results[$key] = $this->unserialize($value);
                } catch (\Throwable) {
                    $results[$key] = null;
                }
            }

            return $results;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('mget', implode(',', $keys), $e);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function performSetMultiple(array $values, ?\DateTimeInterface $expiration): bool
    {
        try {
            // Use a pipeline for atomic and performant multi-set operations.
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

    /**
     * @param array<string> $keys
     */
    private function performDeleteMultiple(array $keys): bool
    {
        $prefixedKeys = array_map(fn ($k) => $this->config->keyPrefix . $k, $keys);

        try {
            $deleted = (int) $this->redis->del($prefixedKeys);

            return $deleted > 0;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('deleteMultiple', implode(',', $keys), $e);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            try {
                $this->validateKey((string) $key);
                $this->validateValue($value);
            } catch (\Throwable $e) {
                if ($e instanceof InvalidArgumentException) {
                    throw $e;
                }
                throw InvalidArgumentException::invalidValue("Invalid value for key '{$key}': " . $e->getMessage(), $e);
            }
        }
    }
}
