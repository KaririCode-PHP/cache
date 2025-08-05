<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedAtomicOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedBatchOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedCrudOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedTagOperations;

/**
 * Memcached driver implementation without expiration parameters.
 */
final class MemcachedDriver implements
    AtomicOperable,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    public function __construct(
        private readonly MemcachedCrudOperations $crudOperations,
        private readonly MemcachedBatchOperations $batchOperations,
        private readonly MemcachedAtomicOperations $atomicOperations,
        private readonly MemcachedTagOperations $tagOperations,
        private readonly MemcachedMonitoringService $monitoringService,
        private readonly MemcachedConnectionManager $connectionManager,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

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

    public function getMultiple(iterable $keys): iterable
    {
        return $this->batchOperations->getMultiple($keys);
    }

    public function setMultiple(iterable $values): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('setMultiple')
        );

        return $this->batchOperations->setMultiple($values, $expiration);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->atomicOperations->increment($key, $step);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->atomicOperations->decrement($key, $step);
    }

    public function add(string $key, mixed $value): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('add')
        );

        return $this->atomicOperations->add($key, $value, $expiration);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return $this->atomicOperations->compareAndSwap($key, $expected, $value, $expiration);
    }

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return $this->tagOperations->setWithTags($key, $value, $tags, $expiration);
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

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
        ];
    }
}
