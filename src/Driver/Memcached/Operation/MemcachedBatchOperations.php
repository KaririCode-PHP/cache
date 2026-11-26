<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles batch operations (get/set/delete multiple) for the MemcachedDriver.
 *
 * This class is solely responsible for multi-key operations, adhering to SRP.
 * It internally uses the ExpirationProvider to calculate the appropriate TTL
 * for batch-set operations, following the Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class MemcachedBatchOperations
{
    private const MAX_BATCH_SIZE = 100;

    private readonly ValidationService $validator;

    /**
     * @param \Memcached $memcached the Memcached client instance
     * @param MemcachedConfig $config configuration settings
     * @param MemcachedErrorHandler $errorHandler error handling and retry logic
     * @param MemcachedConnectionManager $connectionManager connection health management
     * @param MemcachedMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Memcached $memcached,
        private readonly MemcachedConfig $config,
        private readonly MemcachedErrorHandler $errorHandler,
        private readonly MemcachedConnectionManager $connectionManager,
        private readonly MemcachedMonitoringService $monitoringService,
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

        return (array) $this->errorHandler->executeWithRetry(
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
        $values = $this->memcached->getMulti($prefixedKeys);

        if (!is_array($values)) {
            $this->monitoringService->recordError();

            return [];
        }

        $result = [];
        $prefixLength = strlen($this->config->keyPrefix);
        foreach ($values as $prefixedKey => $value) {
            $originalKey = substr($prefixedKey, $prefixLength);
            $result[$originalKey] = $value;
            $this->monitoringService->recordHit();
        }

        foreach ($keys as $key) {
            if (!isset($result[$key])) {
                $this->monitoringService->recordMiss();
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function performSetMultiple(array $values, ?\DateTimeInterface $expiration): bool
    {
        if (count($values) > self::MAX_BATCH_SIZE) {
            return $this->performSetMultipleInBatches($values, $expiration);
        }

        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->config->keyPrefix . $key] = $value;
        }

        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
        $success = $this->memcached->setMulti($prefixedValues, $ttl);

        if ($success) {
            for ($i = 0; $i < count($values); ++$i) {
                $this->monitoringService->recordWrite();
            }
        } else {
            $this->monitoringService->recordError();
        }

        return $success;
    }

    /**
     * @param array<string> $keys
     */
    private function performDeleteMultiple(array $keys): bool
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            return $this->performDeleteMultipleInBatches($keys);
        }

        $prefixedKeys = array_map(fn (string $key) => $this->config->keyPrefix . $key, $keys);
        $result = $this->memcached->deleteMulti($prefixedKeys);

        if (!is_array($result)) {
            $this->monitoringService->recordError();

            return false;
        }

        $allSuccessful = true;
        foreach ($result as $resultCode) {
            if (\Memcached::RES_SUCCESS === $resultCode || \Memcached::RES_NOTFOUND === $resultCode) {
                $this->monitoringService->recordDelete();
            } else {
                $allSuccessful = false;
                $this->monitoringService->recordError();
            }
        }

        return $allSuccessful;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function performSetMultipleInBatches(array $values, ?\DateTimeInterface $expiration): bool
    {
        $batches = array_chunk($values, self::MAX_BATCH_SIZE, true);
        $allSuccessful = true;
        foreach ($batches as $batch) {
            if (!$this->performSetMultiple($batch, $expiration)) {
                $allSuccessful = false;
            }
        }

        return $allSuccessful;
    }

    /**
     * @param array<string> $keys
     */
    private function performDeleteMultipleInBatches(array $keys): bool
    {
        $batches = array_chunk($keys, self::MAX_BATCH_SIZE);
        $allSuccessful = true;
        foreach ($batches as $batch) {
            if (!$this->performDeleteMultiple($batch)) {
                $allSuccessful = false;
            }
        }

        return $allSuccessful;
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
