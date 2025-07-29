<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMaintenanceService;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuAtomicOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuBatchOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuCrudOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTagOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTopicOperations;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Acts as a Facade for all APCu operations.
 * Its single responsibility is to delegate cache operations to the specialized
 * handler classes it is composed of, following SRP and Dependency Injection.
 */
final class ApcuDriver implements
    AtomicOperable,
    TaggableCacheDriver,
    TopicAwareCacheDriver,
    CompareAndSwapAware,
    Maintainable
{
    use ExpirationManagementTrait;

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

    // --- Basic CRUD Operations ---

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
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
        // APCu's native increment does not support custom TTLs or initial values in the same atomic operation.
        // The handler simulates this behavior.
        return $this->atomicOperations->increment($key, $step);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        // APCu's native decrement does not support custom TTLs or initial values in the same atomic operation.
        // The handler simulates this behavior.
        return $this->atomicOperations->decrement($key, $step);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->calculateExpiration($ttl);

        return $this->atomicOperations->add($key, $value, $expiration);
    }

    // --- Compare and Swap ---

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->calculateExpiration($ttl);

        return $this->atomicOperations->compareAndSwap($key, $expected, $value, $expiration);
    }

    // --- Tag Operations ---

    public function setWithTags(
        string $key,
        mixed $value,
        array $tags,
        \DateInterval|int|null $ttl = null
    ): bool {
        $expiration = $this->calculateExpiration($ttl);

        return $this->tagOperations->setWithTags($key, $value, $tags, $expiration);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getByTags(array $tags): array
    {
        $allKeys = [];
        foreach ($tags as $tag) {
            $taggedKeys = $this->tagOperations->getTaggedKeys((string) $tag);
            $allKeys = array_merge($allKeys, $taggedKeys);
        }

        $uniqueKeys = array_unique($allKeys);
        if (empty($uniqueKeys)) {
            return [];
        }

        return $this->batchOperations->getMultiple($uniqueKeys);
    }

    public function getTags(): array
    {
        return $this->tagOperations->getAllTags();
    }

    // --- Topic Operations ---

    public function subscribeTo(string $key, array $topics): void
    {
        $this->topicOperations->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->topicOperations->invalidateTopics($topics);
    }

    public function maintenance(array $options = []): bool
    {
        return $this->maintenanceService->perform($options);
    }

    // --- Statistics, Capabilities, and Maintenance ---

    public function getStats(): DriverStats
    {
        return $this->monitoringService;
    }

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
            CacheCapabilities::TOPICS,
        ];
    }
}
