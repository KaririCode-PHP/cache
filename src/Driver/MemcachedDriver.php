<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedAtomicOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedBatchOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedCrudOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedTagOperations;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Acts as a Facade for all Memcached operations, delegating to specialized handlers.
 */
final class MemcachedDriver implements
    AtomicCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    // FIX: Add the trait to the class.
    use ExpirationManagementTrait;

    public function __construct(
        private readonly MemcachedCrudOperations $crudOperations,
        private readonly MemcachedBatchOperations $batchOperations,
        private readonly MemcachedAtomicOperations $atomicOperations,
        private readonly MemcachedTagOperations $tagOperations,
        private readonly MemcachedMonitoringService $monitoringService,
        private readonly MemcachedConnectionManager $connectionManager
    ) {
    }

    // --- Basic CRUD Operations ---
    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        // NOTE: This signature is already correct. If a TTL were passed here, it would also need conversion.
        return $this->crudOperations->set($key, $value, $expiration);
    }

    public function has(string $key): bool
    {
        return $this->crudOperations->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->crudOperations->delete($key);
    }

    public function clear(): bool
    {
        return $this->crudOperations->clear();
    }

    // --- Batch Operations ---
    public function getMultiple(array $keys): array
    {
        return $this->batchOperations->getMultiple($keys);
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        return $this->batchOperations->setMultiple($values, $expiration);
    }

    public function deleteMultiple(array $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
    }

    // --- Atomic Operations ---
    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->atomicOperations->increment($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->atomicOperations->add($key, $value, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->atomicOperations->compareAndSwap($key, $expected, $value, $ttl);
    }

    // --- Tagging Operations (Implementing TaggableCacheDriver) ---
    /**
     * @param string $key the cache key
     * @param mixed $value the value to cache
     * @param Tag[] $tags an array of Tag objects*
     * @param \DateInterval|int|null $ttl the time-to-live for the cache item, can be an int (seconds), DateInterval, or null
     *
     * @return bool true if the item was successfully cached, false otherwise
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->calculateExpiration($ttl);

        return $this->tagOperations->setWithTags($key, $value, $tags, $expiration);
    }

    /**
     * @param Tag[] $tags an array of Tag objects
     *
     * @return bool true if the tags were successfully invalidated, false otherwise
     */
    public function invalidateTags(array $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    /**
     * @param Tag[] $tags an array of Tag objects
     *
     * @return array <string, mixed> an array of cache items indexed by their keys
     */
    public function getByTags(array $tags): array
    {
        return $this->tagOperations->getByTags($tags);
    }

    public function getTags(): array
    {
        return $this->tagOperations->getTags();
    }

    // --- Statistics and Monitoring (Implementing CacheDriver) ---

    public function getStats(): DriverStats
    {
        return $this->monitoringService->getDriverStats();
    }

    public function getCapabilities(): array
    {
        return [CacheCapabilities::ATOMIC_OPERATIONS, CacheCapabilities::TAGGING];
    }

    public function isHealthy(): bool
    {
        return $this->connectionManager->isHealthy();
    }
}
