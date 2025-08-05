<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
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
 * Redis driver implementation without expiration parameters.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class RedisDriver implements
    AtomicOperable,
    TopicAwareCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    public function __construct(
        private readonly RedisCrudOperations $crudOperations,
        private readonly RedisBatchOperations $batchOperations,
        private readonly RedisAtomicOperations $atomicOperations,
        private readonly RedisTagOperations $tagOperations,
        private readonly RedisTopicOperations $topicOperations,
        private readonly RedisMonitoringService $monitoringService,
        private readonly RedisConnectionManager $connectionManager,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    // Basic CRUD Operations
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

    // Batch Operations
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

    // Atomic Operations
    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('increment')
        );

        return $this->atomicOperations->increment($key, $step, $initialValue, $expiration);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('decrement')
        );

        return $this->atomicOperations->decrement($key, $step, $initialValue, $expiration);
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

    // Tagging Operations
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

    public function getTags(): array
    {
        return $this->tagOperations->getTags();
    }

    // Topic Operations
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

    public function getCapabilities(): array
    {
        return ['atomic', 'tagging', 'topics', 'persistence'];
    }
}
