<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Array\Operation\ArrayAtomicOperations;
use KaririCode\Cache\Driver\Array\Operation\ArrayCrudOperations;
use KaririCode\Cache\Driver\Array\Operation\ArrayTagOperations;
use KaririCode\Cache\Driver\Array\Operation\ArrayTopicOperations;
use KaririCode\Cache\Driver\Array\Storage\ArrayStorage;

/**
 * In-memory array-based cache driver.
 *
 * This class acts as a Facade for all array-based cache operations. Its single
 * responsibility is to delegate method calls to specialized operation handler
 * classes. This design adheres to the Single Responsibility Principle and
 * makes the driver highly modular and easy to maintain.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayDriver
{
    private readonly ArrayStorage $storage;
    private readonly ArrayCrudOperations $crudOperations;
    private readonly ArrayAtomicOperations $atomicOperations;
    private readonly ArrayTagOperations $tagOperations;
    private readonly ArrayTopicOperations $topicOperations;

    public function __construct(
        private readonly ExpirationProvider $expirationProvider
    ) {
        // The ArrayStorage now encapsulates all the raw data arrays and stats.
        $this->storage = new ArrayStorage();

        // The callback ensures that when a key is deleted, it's removed from all indexes.
        $removeCallback = fn (string $key) => $this->storage->deleteKey($key);

        // Compose the driver with specialized operation handlers.
        $this->crudOperations = new ArrayCrudOperations(
            $this->storage,
            $this->expirationProvider
        );
        $this->atomicOperations = new ArrayAtomicOperations(
            $this->storage,
            $this->expirationProvider
        );
        $this->tagOperations = new ArrayTagOperations(
            $this->storage,
            $this->expirationProvider,
            $removeCallback
        );
        $this->topicOperations = new ArrayTopicOperations(
            $this->storage,
            $this->expirationProvider,
            $removeCallback
        );
    }

    // --- Basic CRUD Operations (Delegated) ---

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
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
        return $this->crudOperations->getMultiple($keys);
    }

    public function setMultiple(iterable $values): bool
    {
        return $this->crudOperations->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->crudOperations->deleteMultiple($keys);
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

    // --- Topic Operations (Delegated) ---

    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->topicOperations->subscribeTo($key, $topics);
    }

    public function invalidateTopics(iterable $topics): bool
    {
        return $this->topicOperations->invalidateTopics($topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        return $this->topicOperations->getSubscribedTopics($key);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topicOperations->getSubscribersForTopic($topic);
    }

    // --- Driver Management and Statistics ---

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING_OPERATIONS,
            CacheCapabilities::TOPICS_OPERATIONS,
            CacheCapabilities::MAINTENANCE_OPERATIONS,
        ];
    }

    public function getStats(): DriverStats
    {
        // The storage object now also acts as the stats provider.
        return $this->storage;
    }

    public function maintenance(array $options = []): bool
    {
        $this->storage->cleanupExpired();

        return true;
    }
}
