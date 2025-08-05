<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Handles basic CRUD cache operations for ApcuDriver with ExpirationProvider support.
 *
 * This class is responsible ONLY for get, set, has, delete, and clear operations,
 * following the Single Responsibility Principle. It uses ExpirationProvider
 * for TTL management following the Strategy Pattern.
 *
 * Design Patterns Applied:
 * - Strategy Pattern: ExpirationProvider for TTL management
 * - Single Responsibility: Handles only CRUD operations
 * - Dependency Injection: All dependencies injected via constructor
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuCrudOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param ApcuConfig $config Configuration settings for APCu
     * @param ApcuErrorHandler $errorHandler Error handling and retry logic
     * @param ApcuMonitoringService $monitoringService Performance monitoring
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     */
    public function __construct(
        private readonly ApcuConfig $config,
        private readonly ApcuErrorHandler $errorHandler,
        private readonly ApcuMonitoringService $monitoringService,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * Retrieves a cache item from APCu.
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
            'get',
            $key
        );
    }

    /**
     * Stores a cache item in APCu with TTL support.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param \DateTimeInterface|null $expiration Optional expiration time
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the item was successfully stored
     */
    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // Calculate expiration using ExpirationProvider if not provided
        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $finalExpiration),
            'set',
            $key
        );
    }

    /**
     * Checks if a cache item exists in APCu.
     *
     * @param string $key The cache key to check
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the key exists
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => apcu_exists($this->config->keyPrefix . $key),
            'has',
            $key
        );
    }

    /**
     * Deletes a cache item from APCu.
     *
     * @param string $key The cache key to delete
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the item was successfully deleted or didn't exist
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDelete($key),
            'delete',
            $key
        );
    }

    /**
     * Clears all cache items from APCu.
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the cache was successfully cleared
     */
    public function clear(): bool
    {
        return (bool) $this->errorHandler->executeWithRetry(
            fn () => apcu_clear_cache(),
            'clear'
        );
    }

    // ========================================================================
    // Private Implementation Methods
    // ========================================================================

    /**
     * Internal get operation implementation.
     */
    private function performGet(string $key): mixed
    {
        $fullKey = $this->config->keyPrefix . $key;
        $value = apcu_fetch($fullKey, $success);

        if ($success) {
            $this->monitoringService->recordHit();

            return $this->unserialize($value);
        }

        $this->monitoringService->recordMiss();

        return null;
    }

    /**
     * Internal set operation implementation.
     */
    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);

        // Calculate TTL in seconds
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $success = apcu_store($fullKey, $serialized, $ttl);

        if ($success) {
            $this->monitoringService->recordWrite();
        } else {
            $this->monitoringService->recordError();
        }

        return $success;
    }

    /**
     * Internal delete operation implementation.
     */
    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        // apcu_delete returns false if key doesn't exist, but we consider that success
        $result = apcu_delete($fullKey);

        // Record as successful operation regardless of whether key existed
        $this->monitoringService->recordWrite();

        return true;
    }
}
