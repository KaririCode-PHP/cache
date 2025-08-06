<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles basic CRUD operations for the MemcachedDriver.
 *
 * This class is solely responsible for get, set, has, delete, and clear
 * operations, adhering to the Single Responsibility Principle. It internally
 * uses the ExpirationProvider to calculate TTLs for write operations.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class MemcachedCrudOperations
{
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
     * Gets a value from the cache.
     *
     * @param string $key the cache key to retrieve
     *
     * @throws CacheException if the operation fails
     *
     * @return mixed the cached value or null if not found
     */
    public function get(string $key): mixed
    {
        $this->validator->validateKey($key);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGet($key),
            $this->connectionManager,
            'get',
            $key
        );
    }

    /**
     * Sets a value in the cache, calculating the TTL internally.
     *
     * @param string $key the cache key
     * @param mixed $value the value to cache
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success, false on failure
     */
    public function set(string $key, mixed $value): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'set');

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $finalExpiration),
            $this->connectionManager,
            'set',
            $key
        );
    }

    /**
     * Checks if a key exists in the cache.
     *
     * @param string $key the cache key to check
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the key exists and is not expired
     */
    public function has(string $key): bool
    {
        $this->validator->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performHas($key),
            $this->connectionManager,
            'has',
            $key
        );
    }

    /**
     * Deletes a key from the cache.
     *
     * @param string $key the cache key to delete
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success or if the key didn't exist
     */
    public function delete(string $key): bool
    {
        $this->validator->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDelete($key),
            $this->connectionManager,
            'delete',
            $key
        );
    }

    /**
     * Clears all cache entries from the server(s).
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success
     */
    public function clear(): bool
    {
        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performClear(),
            $this->connectionManager,
            'clear'
        );
    }

    // ========================================================================
    // Private Implementation Methods
    // ========================================================================

    private function performGet(string $key): mixed
    {
        $fullKey = $this->config->keyPrefix . $key;
        $value = $this->memcached->get($fullKey);

        if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            $this->monitoringService->recordMiss();

            return null;
        }

        if (\Memcached::RES_SUCCESS !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('get', $key, new \RuntimeException($this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordHit();

        return $value;
    }

    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = $this->memcached->set($fullKey, $value, $ttl);

        if (!$result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('set', $key, new \RuntimeException($this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordWrite();

        return true;
    }

    private function performHas(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        // A `get` call is the most reliable way to check existence in Memcached.
        $this->memcached->get($fullKey);
        $resultCode = $this->memcached->getResultCode();

        if (\Memcached::RES_SUCCESS === $resultCode) {
            return true;
        }
        if (\Memcached::RES_NOTFOUND === $resultCode) {
            return false;
        }

        throw CacheException::operationFailed('has', $key, new \RuntimeException($this->memcached->getResultMessage()));
    }

    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $this->memcached->delete($fullKey);
        $resultCode = $this->memcached->getResultCode();

        // Both success and not found are considered a successful deletion.
        if (\Memcached::RES_SUCCESS === $resultCode || \Memcached::RES_NOTFOUND === $resultCode) {
            $this->monitoringService->recordDelete();

            return true;
        }

        $this->monitoringService->recordError();
        throw CacheException::operationFailed('delete', $key, new \RuntimeException($this->memcached->getResultMessage()));
    }

    private function performClear(): bool
    {
        $result = $this->memcached->flush();

        if (!$result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('clear', '', new \RuntimeException($this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordClear();

        return true;
    }
}
