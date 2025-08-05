<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Handles basic CRUD operations for Memcached.
 *
 * This class is responsible ONLY for get, set, has, delete, and clear operations,
 * following the Single Responsibility Principle. It uses ExpirationProvider
 * for TTL management and delegates error handling and monitoring to specialized
 * components.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       https://docs.kariricode.org/cache/drivers/memcached
 *
 * @example
 * ```php
 * $crud = new MemcachedCrudOperations(
 *     $memcached,
 *     $config,
 *     $errorHandler,
 *     $connectionManager,
 *     $monitoringService,
 *     $expirationProvider
 * );
 *
 * // Basic operations with automatic TTL calculation
 * $crud->set('user:123', $userData);
 * $userData = $crud->get('user:123');
 * $crud->delete('user:123');
 * ```
 */
final class MemcachedCrudOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    /**
     * @param \Memcached $memcached The Memcached instance
     * @param MemcachedConfig $config Configuration settings
     * @param MemcachedErrorHandler $errorHandler Error handling and retry logic
     * @param MemcachedConnectionManager $connectionManager Connection health management
     * @param MemcachedMonitoringService $monitoringService Performance monitoring
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
        // Initialize if needed
    }

    /**
     * Gets a value from the cache.
     *
     * @param string $key The cache key to retrieve
     *
     * @throws CacheException if the operation fails
     *
     * @return mixed The cached value or null if not found
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
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param \DateTimeInterface|null $expiration Optional expiration override
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True on success, false on failure
     */
    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // Use provided expiration or calculate from ExpirationProvider
        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

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
     * @param string $key The cache key to check
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True if the key exists and is not expired
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
     *
     * @param string $key The cache key to delete
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True on success, false if key didn't exist
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
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True on success
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

    /**
     * Performs the actual get operation.
     *
     * @param string $key The cache key
     *
     * @return mixed The cached value or null if not found
     */
    private function performGet(string $key): mixed
    {
        $fullKey = $this->config->keyPrefix . $key;
        $value = $this->memcached->get($fullKey);

        // Check if the key was not found
        if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            $this->monitoringService->recordMiss();

            return null;
        }

        // Check for other errors
        if (\Memcached::RES_SUCCESS !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('get', $key, new \RuntimeException('Memcached get operation failed: ' . $this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordHit();

        return $value;
    }

    /**
     * Performs the actual set operation.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param \DateTimeInterface|null $expiration The expiration time
     *
     * @return bool True on success
     */
    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        // Calculate TTL in seconds
        $ttl = 0; // 0 means no expiration
        if (null !== $expiration) {
            $ttl = max(0, $expiration->getTimestamp() - time());
        }

        $result = $this->memcached->set($fullKey, $value, $ttl);

        if (!$result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('set', $key, new \RuntimeException('Memcached set operation failed: ' . $this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordSet();

        return true;
    }

    /**
     * Performs the actual has operation.
     *
     * @param string $key The cache key
     *
     * @return bool True if the key exists
     */
    private function performHas(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        // Use get operation but ignore the value
        $this->memcached->get($fullKey);

        $resultCode = $this->memcached->getResultCode();

        if (\Memcached::RES_SUCCESS === $resultCode) {
            $this->monitoringService->recordHit();

            return true;
        }

        if (\Memcached::RES_NOTFOUND === $resultCode) {
            $this->monitoringService->recordMiss();

            return false;
        }

        // Other error occurred
        $this->monitoringService->recordError();
        throw CacheException::operationFailed('has', $key, new \RuntimeException('Memcached has operation failed: ' . $this->memcached->getResultMessage()));
    }

    /**
     * Performs the actual delete operation.
     *
     * @param string $key The cache key
     *
     * @return bool True on success
     */
    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $result = $this->memcached->delete($fullKey);

        $resultCode = $this->memcached->getResultCode();

        // Both success and not found are considered successful deletions
        if (\Memcached::RES_SUCCESS === $resultCode || \Memcached::RES_NOTFOUND === $resultCode) {
            $this->monitoringService->recordDelete();

            return true;
        }

        $this->monitoringService->recordError();
        throw CacheException::operationFailed('delete', $key, new \RuntimeException('Memcached delete operation failed: ' . $this->memcached->getResultMessage()));
    }

    /**
     * Performs the actual clear operation.
     *
     * @return bool True on success
     */
    private function performClear(): bool
    {
        $result = $this->memcached->flush();

        if (!$result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('clear', '', new \RuntimeException('Memcached flush operation failed: ' . $this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordClear();

        return true;
    }

    // ========================================================================
    // Utility Methods for Advanced Operations
    // ========================================================================

    /**
     * Adds a value to the cache only if the key doesn't exist.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param \DateTimeInterface|null $expiration Optional expiration override
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True if the value was added, false if key already exists
     */
    public function add(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performAdd($key, $value, $finalExpiration),
            $this->connectionManager,
            'add',
            $key
        );
    }

    /**
     * Replaces a value in the cache only if the key already exists.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param \DateTimeInterface|null $expiration Optional expiration override
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True if the value was replaced, false if key doesn't exist
     */
    public function replace(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performReplace($key, $value, $finalExpiration),
            $this->connectionManager,
            'replace',
            $key
        );
    }

    /**
     * Performs the actual add operation.
     */
    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = $this->memcached->add($fullKey, $value, $ttl);

        if (!$result) {
            $resultCode = $this->memcached->getResultCode();

            // Not stored because key exists is not an error for add
            if (\Memcached::RES_NOTSTORED === $resultCode) {
                return false;
            }

            $this->monitoringService->recordError();
            throw CacheException::operationFailed('add', $key, new \RuntimeException('Memcached add operation failed: ' . $this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordSet();

        return true;
    }

    /**
     * Performs the actual replace operation.
     */
    private function performReplace(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = $this->memcached->replace($fullKey, $value, $ttl);

        if (!$result) {
            $resultCode = $this->memcached->getResultCode();

            // Not stored because key doesn't exist is not an error for replace
            if (\Memcached::RES_NOTSTORED === $resultCode) {
                return false;
            }

            $this->monitoringService->recordError();
            throw CacheException::operationFailed('replace', $key, new \RuntimeException('Memcached replace operation failed: ' . $this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordSet();

        return true;
    }

    /**
     * Gets detailed statistics about cache operations.
     *
     * @return array<string, mixed> Performance metrics and operational statistics
     */
    public function getOperationStats(): array
    {
        return [
            'memcached_version' => $this->memcached->getVersion(),
            'memcached_stats' => $this->memcached->getStats(),
            'result_code' => $this->memcached->getResultCode(),
            'result_message' => $this->memcached->getResultMessage(),
            'config' => [
                'key_prefix' => $this->config->keyPrefix,
                'compression' => $this->config->compression,
                'serializer' => $this->config->serializer,
            ],
        ];
    }

    /**
     * Touches a key to extend its expiration time.
     *
     * @param string $key The cache key
     * @param \DateTimeInterface|null $expiration New expiration time
     *
     * @return bool True on success
     */
    public function touch(string $key, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performTouch($key, $finalExpiration),
            $this->connectionManager,
            'touch',
            $key
        );
    }

    /**
     * Performs the actual touch operation.
     */
    private function performTouch(string $key, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = $this->memcached->touch($fullKey, $ttl);

        if (!$result) {
            $resultCode = $this->memcached->getResultCode();

            if (\Memcached::RES_NOTFOUND === $resultCode) {
                return false; // Key doesn't exist
            }

            $this->monitoringService->recordError();
            throw CacheException::operationFailed('touch', $key, new \RuntimeException('Memcached touch operation failed: ' . $this->memcached->getResultMessage()));
        }

        return true;
    }
}
