<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Handles batch operations for Memcached (multi-key operations).
 * SRP: This class is only responsible for multi-key operations.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class MemcachedBatchOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    private const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private MemcachedErrorHandler $errorHandler,
        private MemcachedConnectionManager $connectionManager,
        private MemcachedMonitoringService $monitoringService,
        private ExpirationProvider $expirationProvider
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
        $this->validateMultipleValues($values);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('setMultiple')
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($values, $finalExpiration),
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
        $prefixedKeys = array_map(fn (string $key) => $this->config->keyPrefix . $key, $keys);
        $values = $this->memcached->getMulti($prefixedKeys);

        if (!is_array($values)) {
            $this->monitoringService->recordError();

            return [];
        }

        // Remove prefix from keys in the result
        $result = [];
        $prefixLength = strlen($this->config->keyPrefix);

        foreach ($values as $prefixedKey => $value) {
            $originalKey = substr($prefixedKey, $prefixLength);
            $result[$originalKey] = $value;
            $this->monitoringService->recordHit();
        }

        // Record misses for keys that weren't found
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

        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;
        $success = $this->memcached->setMulti($prefixedValues, $ttlInSeconds);

        if ($success) {
            foreach ($values as $key => $value) {
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
