<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Service\ValidationService; // Assuming a future refactor to use ValidationService

/**
 * Handles basic CRUD cache operations for the ApcuDriver.
 *
 * This class is responsible ONLY for get, set, has, delete, and clear operations,
 * adhering to the Single Responsibility Principle. It internally uses the
 * ExpirationProvider to calculate TTLs, following the Strategy Pattern.
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

    private readonly ValidationService $validator;

    /**
     * @param ApcuConfig $config configuration settings for APCu
     * @param ApcuErrorHandler $errorHandler error handling and retry logic
     * @param ApcuMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider strategy for TTL management
     */
    public function __construct(
        private readonly ApcuConfig $config,
        private readonly ApcuErrorHandler $errorHandler,
        private readonly ApcuMonitoringService $monitoringService,
        private readonly ExpirationProvider $expirationProvider
    ) {
        // In a future refactor, this could be injected instead of instantiated.
        $this->validator = new ValidationService();
    }

    /**
     * Retrieves a cache item from APCu.
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
            'get',
            $key
        );
    }

    /**
     * Stores a cache item in APCu, calculating the TTL internally.
     *
     * @param string $key the cache key
     * @param mixed $value the value to cache
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the item was successfully stored
     */
    public function set(string $key, mixed $value): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);

        // Calculate expiration using the provider's strategy.
        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'set');

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $finalExpiration),
            'set',
            $key
        );
    }

    /**
     * Checks if a cache item exists in APCu.
     *
     * @param string $key the cache key to check
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the key exists
     */
    public function has(string $key): bool
    {
        $this->validator->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => apcu_exists($this->config->keyPrefix . $key),
            'has',
            $key
        );
    }

    /**
     * Deletes a cache item from APCu.
     *
     * @param string $key the cache key to delete
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the item was successfully deleted or didn't exist
     */
    public function delete(string $key): bool
    {
        $this->validator->validateKey($key);

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

        // Calculate TTL in seconds from the DateTime object.
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

        // apcu_delete returns false if key doesn't exist, but for a clear
        // cache operation, we consider that a success.
        apcu_delete($fullKey);

        $this->monitoringService->recordDelete();

        return true;
    }
}
