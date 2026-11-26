<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMaintenanceService;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuAtomicOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuBatchOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuCrudOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTagOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTopicOperations;

/**
 * Acts as a pure Facade for all APCu operations.
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
final class ApcuDriver
{
    /**
     * @param ApcuCrudOperations $crudOperations handles basic get/set/delete operations
     * @param ApcuBatchOperations $batchOperations handles multi-key operations
     * @param ApcuAtomicOperations $atomicOperations handles increment/decrement/add operations
     * @param ApcuTagOperations $tagOperations handles tag-based operations
     * @param ApcuTopicOperations $topicOperations handles topic-based invalidation
     * @param ApcuMonitoringService $monitoringService provides driver statistics
     * @param ApcuMaintenanceService $maintenanceService handles cleanup tasks
     */
    public function __construct(
        private readonly ApcuCrudOperations $crudOperations,
        private readonly ApcuBatchOperations $batchOperations,
        private readonly ApcuAtomicOperations $atomicOperations,
        private readonly ApcuTagOperations $tagOperations,
        private readonly ApcuTopicOperations $topicOperations,
        private readonly ApcuMonitoringService $monitoringService,
        private readonly ApcuMaintenanceService $maintenanceService
    ) {
    }

    // --- Basic CRUD Operations (Delegated) ---

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        // The responsibility of handling TTL is now inside ApcuCrudOperations.
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
        // The responsibility of handling TTL is now inside ApcuBatchOperations.
        return $this->batchOperations->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
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

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getByTags(iterable $tags): iterable
    {
        return $this->tagOperations->getByTags($tags);
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
        return $this->monitoringService;
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
}
