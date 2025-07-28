<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Handles batch operations for Memcached (multi-key operations).
 * SRP: This class is only responsible for multi-key operations.
 */
final class MemcachedBatchOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;

    private const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private MemcachedErrorHandler $errorHandler,
        private MemcachedConnectionManager $connectionManager,
        private MemcachedMonitoringService $monitoringService
    ) {
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $this->validateKeys($keys);

        return (array) $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keys),
            $this->connectionManager,
            'getMultiple'
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

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($values, $expiration),
            $this->connectionManager,
            'setMultiple'
        );
    }

    /**
     * @param array<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        if (empty($keys)) {
            return true;
        }
        $this->validateKeys($keys);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteMultiple($keys),
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
        if (count($keys) > self::MAX_BATCH_SIZE) {
            return $this->performGetMultipleInBatches($keys);
        }

        $prefixedKeys = array_map(fn ($key) => $this->config->keyPrefix . $key, $keys);
        $values = $this->memcached->getMulti($prefixedKeys);

        if (false === $values) {
            $resultCode = $this->memcached->getResultCode();
            if (\Memcached::RES_NOTFOUND !== $resultCode) {
                throw CacheException::operationFailed('getMultiple', implode(',', $keys));
            }
            $values = [];
        }

        $result = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->config->keyPrefix . $key;
            if (array_key_exists($prefixedKey, $values)) {
                $result[$key] = $values[$prefixedKey];
                $this->monitoringService->recordHit();
            } else {
                $result[$key] = null;
                $this->monitoringService->recordMiss();
            }
        }

        return $result;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    private function performGetMultipleInBatches(array $keys): array
    {
        $result = [];
        $batches = array_chunk($keys, self::MAX_BATCH_SIZE);
        foreach ($batches as $batch) {
            $batchResult = $this->performGetMultiple($batch);
            $result = array_merge($result, $batchResult);
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

        $ttlInSeconds = $this->calculateTtlInSeconds($expiration);
        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->config->keyPrefix . $key] = $value;
        }

        $result = $this->memcached->setMulti($prefixedValues, $ttlInSeconds);

        if ($result) {
            $count = count($values);
            for ($i = 0; $i < $count; ++$i) {
                $this->monitoringService->recordWrite();
            }
        } else {
            $this->monitoringService->recordError();
        }

        return $result;
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
    private function performDeleteMultiple(array $keys): bool
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            return $this->performDeleteMultipleInBatches($keys);
        }

        $prefixedKeys = array_map(fn ($key) => $this->config->keyPrefix . $key, $keys);
        $result = $this->memcached->deleteMulti($prefixedKeys);

        // The modern memcached extension returns an array of statuses.
        if (!is_array($result)) {
            // This case should ideally not happen with modern extensions, but we handle it.
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
