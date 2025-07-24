<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Handles basic CRUD operations for Memcached.
 * SRP: This class is only responsible for get, set, has, delete, and clear operations.
 */
final class MemcachedCrudOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;

    public function __construct(
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private MemcachedErrorHandler $errorHandler,
        private MemcachedConnectionManager $connectionManager,
        private MemcachedMonitoringService $monitoringService
    ) {
    }

    /**
     * Gets a value from the cache.
     */
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

    /**
     * Sets a value in the cache.
     */
    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $expiration),
            $this->connectionManager,
            'set',
            $key
        );
    }

    /**
     * Checks if a key exists in the cache.
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performHas($key),
            $this->connectionManager,
            'has',
            $key
        );
    }

    /**
     * Deletes a key from the cache.
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDelete($key),
            $this->connectionManager,
            'delete',
            $key
        );
    }

    /**
     * Clears all cache entries.
     */
    public function clear(): bool
    {
        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performClear(),
            $this->connectionManager,
            'clear'
        );
    }

    private function performGet(string $key): mixed
    {
        $fullKey = $this->config->keyPrefix . $key;
        $value = $this->memcached->get($fullKey);

        if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            $this->monitoringService->recordMiss();

            return null;
        }

        $this->monitoringService->recordHit();

        return $value;
    }

    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $this->calculateTtlInSeconds($expiration);
        $result = $this->memcached->set($fullKey, $value, $ttlInSeconds);

        if ($result) {
            $this->monitoringService->recordWrite();
        } else {
            $this->monitoringService->recordError();
        }

        return $result;
    }

    private function performHas(string $key): bool
    {
        // Memcached has no native "exists" command.
        // The official recommendation is to use `get` and check the result code.
        $fullKey = $this->config->keyPrefix . $key;
        $this->memcached->get($fullKey);

        return \Memcached::RES_SUCCESS === $this->memcached->getResultCode();
    }

    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $result = $this->memcached->delete($fullKey);

        // Deleting a non-existent key is a successful outcome.
        if ($result || \Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            $this->monitoringService->recordDelete();

            return true;
        }

        $this->monitoringService->recordError();

        return false;
    }

    private function performClear(): bool
    {
        // WARNING: flush() invalidates all items on all servers immediately
        // or after a specified delay. It does not respect prefixes.
        $result = $this->memcached->flush();

        if (!$result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('clear', 'all');
        }

        return true;
    }
}
