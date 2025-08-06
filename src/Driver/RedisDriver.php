<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisMaintenanceService;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Operation\RedisAtomicOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisBatchOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisCrudOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTagOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTopicOperations;
use KaririCode\Cache\Item\CacheItem;

/**
 * Acts as a pure Facade for all Redis operations.
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
final class RedisDriver implements CacheDriver
{
    /**
     * @param RedisCrudOperations $crudOperations handles basic get/set/delete operations
     * @param RedisBatchOperations $batchOperations handles multi-key operations
     * @param RedisAtomicOperations $atomicOperations handles increment/decrement/add operations
     * @param RedisTagOperations $tagOperations handles tag-based operations
     * @param RedisTopicOperations $topicOperations handles topic-based invalidation
     * @param RedisMaintenanceService $maintenanceService handles cleanup and maintenance tasks
     * @param RedisMonitoringService $monitoringService provides driver statistics
     * @param RedisConnectionManager $connectionManager manages the connection lifecycle
     */
    public function __construct(
        private readonly RedisCrudOperations $crudOperations,
        private readonly RedisBatchOperations $batchOperations,
        private readonly RedisAtomicOperations $atomicOperations,
        private readonly RedisTagOperations $tagOperations,
        private readonly RedisTopicOperations $topicOperations,
        private readonly RedisMaintenanceService $maintenanceService,
        private readonly RedisMonitoringService $monitoringService,
        private readonly RedisConnectionManager $connectionManager
    ) {
    }

    // --- Basic CRUD Operations (Delegated) ---

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        // The responsibility of handling TTL is now inside RedisCrudOperations.
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
        // The responsibility of handling TTL is now inside RedisBatchOperations.
        return $this->batchOperations->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
    }

    public function getItem(string $key): \Psr\Cache\CacheItemInterface
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

    public function save(\Psr\Cache\CacheItemInterface $item): bool
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

    // --- Statistics, Capabilities, and Maintenance (Delegated) ---

    public function getStats(): DriverStats
    {
        return $this->monitoringService->getStats();
    }

    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        // Redis driver doesn't inherently support deferred saving.
        // We'll save immediately, effectively making saveDeferred behave like save.
        return $this->save($item);
    }

    public function commit(): bool
    {
        // No deferred operations to commit for the Redis driver.
        return true;
    }

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING_OPERATIONS,
            CacheCapabilities::TOPICS_OPERATIONS,
            CacheCapabilities::MAINTENANCE_OPERATIONS,
        ];
    }

    public function maintenance(array $options = []): bool
    {
        return $this->maintenanceService->perform($options);
    }

    public function isConnected(): bool
    {
        return $this->connectionManager->isHealthy();
    }
}
