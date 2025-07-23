<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Operation\RedisAtomicOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisBatchOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisCrudOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTagOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTopicOperations;

/**
 * Acts as a Facade for all Redis operations.
 * Its single responsibility is to delegate cache operations to the specialized
 * handler classes it is composed of, following SRP and Dependency Injection.
 */
final class RedisDriver implements
    AtomicCacheDriver,
    TopicAwareCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    /**
     * Receives pre-configured components to delegate operations to.
     */
    public function __construct(
        private readonly RedisCrudOperations $crudOperations,
        private readonly RedisBatchOperations $batchOperations,
        private readonly RedisAtomicOperations $atomicOperations,
        private readonly RedisTagOperations $tagOperations,
        private readonly RedisTopicOperations $topicOperations,
        private readonly RedisMonitoringService $monitoringService,
        private readonly RedisConnectionManager $connectionManager
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

    // --- Tagging Operations ---

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $tagStrings = array_map('strval', $tags);

        return $this->tagOperations->setWithTags($key, $value, $tagStrings, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getByTags(array $tags): array
    {
        return $this->tagOperations->getByTags($tags);
    }

    public function getTags(): array
    {
        return $this->tagOperations->getTags();
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

    // --- Monitoring & Management ---

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
            CacheCapabilities::TOPICS,
            CacheCapabilities::TRANSACTIONS,
        ];
    }

    public function getStats(): DriverStats
    {
        return $this->monitoringService->getStats();
    }

    public function isHealthy(): bool
    {
        return $this->connectionManager->isHealthy();
    }

    public function maintenance(array $options = []): bool
    {
        return $this->monitoringService->performMaintenance($options);
    }
}
