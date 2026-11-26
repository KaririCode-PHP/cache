<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedAtomicOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedBatchOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedCrudOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedTagOperations;
use KaririCode\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

/**
 * Acts as a pure Facade for all Memcached operations.
 *
 * Its single responsibility is to delegate cache operations to the specialized
 * handler classes it is composed of. It does not contain any business logic,
 * adhering strictly to the Facade and Single Responsibility principles.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class MemcachedDriver implements CacheDriver
{
    /**
     * @param MemcachedCrudOperations $crudOperations handles basic get/set/delete operations
     * @param MemcachedBatchOperations $batchOperations handles multi-key operations
     * @param MemcachedAtomicOperations $atomicOperations handles increment/decrement/add operations
     * @param MemcachedTagOperations $tagOperations handles tag-based operations
     * @param MemcachedMonitoringService $monitoringService provides driver statistics
     * @param MemcachedConnectionManager $connectionManager manages the connection lifecycle
     */
    public function __construct(
        private readonly MemcachedCrudOperations $crudOperations,
        private readonly MemcachedBatchOperations $batchOperations,
        private readonly MemcachedAtomicOperations $atomicOperations,
        private readonly MemcachedTagOperations $tagOperations,
        private readonly MemcachedMonitoringService $monitoringService,
        private readonly MemcachedConnectionManager $connectionManager
    ) {
    }

    // --- Basic CRUD Operations (Delegated) ---

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        // The responsibility of handling TTL is now inside MemcachedCrudOperations.
        return $this->crudOperations->set($key, $value);
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

    // --- Batch Operations (Delegated) ---

    public function getMultiple(iterable $keys): iterable
    {
        return $this->batchOperations->getMultiple($keys);
    }

    public function setMultiple(iterable $values): bool
    {
        // The responsibility of handling TTL is now inside MemcachedBatchOperations.
        return $this->batchOperations->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
    }

    public function getItem(string $key): CacheItemInterface
    {
        $value = $this->crudOperations->get($key);

        return new CacheItem($key, $value, null !== $value);
    }

    public function getItems(iterable $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->crudOperations->has($key);
    }

    public function deleteItem(string $key): bool
    {
        return $this->crudOperations->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->crudOperations->set($item->getKey(), $item->get());
    }

    // --- Atomic Operations (Delegated) ---

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->atomicOperations->increment($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->atomicOperations->decrement($key, $step, $initialValue);
    }

    public function add(string $key, mixed $value): bool
    {
        return $this->atomicOperations->add($key, $value);
    }

    // --- Compare and Swap (Delegated) ---

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        return $this->atomicOperations->compareAndSwap($key, $expected, $value);
    }

    // --- Tag Operations (Delegated) ---

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        return $this->tagOperations->setWithTags($key, $value, $tags);
    }

    public function getByTags(iterable $tags): iterable
    {
        return $this->tagOperations->getByTags($tags);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getTags(): iterable
    {
        return $this->tagOperations->getTags();
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        // Memcached driver doesn't inherently support deferred saving.
        // We'll save immediately, effectively making saveDeferred behave like save.
        return $this->save($item);
    }

    public function commit(): bool
    {
        // No deferred operations to commit for the Memcached driver.
        return true;
    }
    // --- Statistics, Capabilities, and Connection ---

    public function getStats(): DriverStats
    {
        return $this->monitoringService;
    }

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING_OPERATIONS,
        ];
    }

    public function isConnected(): bool
    {
        return $this->connectionManager->isHealthy();
    }
}
