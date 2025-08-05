<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Handles batch operations for Redis (multi-key operations).
 * SRP: This class is only responsible for multi-key operations.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class RedisBatchOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    private const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys): iterable
    {
        $keyArray = $this->normalizeToArray($keys);
        if (empty($keyArray)) {
            return [];
        }

        $this->validateKeys($keyArray);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keyArray),
            $this->connectionManager,
            'getMultiple'
        );
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, ?\DateTimeInterface $expiration = null): bool
    {
        $valueArray = $this->normalizeToArray($values);
        if (empty($valueArray)) {
            return true;
        }

        $this->validateMultipleValues($valueArray);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('setMultiple')
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($valueArray, $finalExpiration),
            $this->connectionManager,
            'setMultiple'
        );
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = $this->normalizeToArray($keys);
        if (empty($keyArray)) {
            return true;
        }

        $this->validateKeys($keyArray);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteMultiple($keyArray),
            $this->connectionManager,
            'deleteMultiple'
        );
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string, mixed>
     */
    private function performGetMultiple(array $keys): array
    {
        $prefixedKeys = array_map(fn (string $key) => $this->config->keyPrefix . $key, $keys);
        $values = $this->redis->mGet($prefixedKeys);

        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($keys as $index => $originalKey) {
            $value = $values[$index] ?? false;
            if (false !== $value) {
                $result[$originalKey] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function performSetMultiple(array $values, ?\DateTimeInterface $expiration): bool
    {
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $fullKey = $this->config->keyPrefix . $key;
            $serializedValue = $this->serialize($value);

            if ($expiration) {
                $ttl = max(1, $expiration->getTimestamp() - time());
                $pipeline->setex($fullKey, $ttl, $serializedValue);
            } else {
                $pipeline->set($fullKey, $serializedValue);
            }
        }

        $results = $pipeline->exec();

        return is_array($results) && !in_array(false, $results, true);
    }

    /**
     * @param array<string> $keys
     */
    private function performDeleteMultiple(array $keys): bool
    {
        $prefixedKeys = array_map(fn (string $key) => $this->config->keyPrefix . $key, $keys);

        $deletedCount = $this->redis->del($prefixedKeys);

        return $deletedCount >= 0;
    }

    /**
     * @param iterable<mixed> $iterable
     *
     * @return array<mixed>
     */
    private function normalizeToArray(iterable $iterable): array
    {
        if (is_array($iterable)) {
            return $iterable;
        }

        return iterator_to_array($iterable);
    }

    /**
     * @param array<string> $keys
     */
    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->validateKey((string) $key);
            $this->validateValue($value);
        }
    }
}
