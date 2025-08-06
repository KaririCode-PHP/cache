<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles batch operations (get/set/delete multiple) for the RedisDriver.
 *
 * This class is solely responsible for multi-key operations, adhering to SRP.
 * It internally uses the ExpirationProvider to calculate the appropriate TTL
 * for batch-set operations and leverages Redis pipelines for efficiency.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class RedisBatchOperations
{
    use SerializationTrait;

    private readonly ValidationService $validator;

    /**
     * @param \Redis $redis the Redis client instance
     * @param RedisConfig $config configuration settings
     * @param RedisErrorHandler $errorHandler error handling and retry logic
     * @param RedisConnectionManager $connectionManager connection health management
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly RedisConfig $config,
        private readonly RedisErrorHandler $errorHandler,
        private readonly RedisConnectionManager $connectionManager,
        private readonly ExpirationProvider $expirationProvider
    ) {
        $this->validator = new ValidationService();
    }

    /**
     * Retrieves multiple items from the cache.
     *
     * @param iterable<string> $keys a list of keys to retrieve
     *
     * @return iterable<string, mixed> a map of keys to their values
     */
    public function getMultiple(iterable $keys): iterable
    {
        $keyArray = $this->normalizeToArray($keys);
        if (empty($keyArray)) {
            return [];
        }
        $this->validator->validateKeys($keyArray);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keyArray),
            $this->connectionManager,
            'getMultiple'
        );
    }

    /**
     * Stores multiple items in the cache, calculating the TTL internally.
     *
     * @param iterable<string, mixed> $values a map of keys to their values
     *
     * @return bool true on success
     */
    public function setMultiple(iterable $values): bool
    {
        $valueArray = $this->normalizeToArray($values);
        if (empty($valueArray)) {
            return true;
        }
        $this->validator->validateMultipleValues($valueArray);

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, null, 'setMultiple');

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($valueArray, $finalExpiration),
            $this->connectionManager,
            'setMultiple'
        );
    }

    /**
     * Deletes multiple items from the cache.
     *
     * @param iterable<string> $keys a list of keys to delete
     *
     * @return bool true on success
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = $this->normalizeToArray($keys);
        if (empty($keyArray)) {
            return true;
        }
        $this->validator->validateKeys($keyArray);

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
                $result[$originalKey] = $this->unserialize($value);
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
        return is_array($iterable) ? $iterable : iterator_to_array($iterable);
    }
}
