<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles basic CRUD operations for the RedisDriver.
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
final class RedisCrudOperations
{
    private readonly ValidationService $validator;

    /**
     * @param \Redis $redis the Redis client instance
     * @param RedisConfig $config configuration settings
     * @param RedisErrorHandler $errorHandler error handling and retry logic
     * @param RedisConnectionManager $connectionManager connection health management
     * @param RedisMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly RedisConfig $config,
        private readonly RedisErrorHandler $errorHandler,
        private readonly RedisConnectionManager $connectionManager,
        private readonly RedisMonitoringService $monitoringService,
        private readonly ExpirationProvider $expirationProvider
    ) {
        $this->validator = new ValidationService();
    }

    /**
     * Retrieves a value from the cache.
     *
     * @param string $key the cache key to retrieve
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
     * @return bool true if the key exists
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
     * Clears all cache entries from the current Redis database.
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
        $value = $this->redis->get($fullKey);

        if (false === $value) {
            // $this->monitoringService->recordMiss(); // Assuming monitoring service exists
            return null;
        }

        // $this->monitoringService->recordHit();
        return $value;
    }

    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        if (null !== $expiration) {
            $ttl = max(1, $expiration->getTimestamp() - time());

            return $this->redis->setex($fullKey, $ttl, $value);
        }

        return $this->redis->set($fullKey, $value);
    }

    private function performHas(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        return $this->redis->exists($fullKey) > 0;
    }

    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        return $this->redis->del($fullKey) >= 0; // DEL returns num keys deleted, 0 if not found
    }

    private function performClear(): bool
    {
        return $this->redis->flushDB();
    }
}
