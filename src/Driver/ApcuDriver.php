<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMaintenanceService;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuAtomicOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuBatchOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuCrudOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTagOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTopicOperations;

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
    public function __construct(
        private readonly ApcuCrudOperations $crudOperations,
        private readonly ApcuBatchOperations $batchOperations,
        private readonly ApcuAtomicOperations $atomicOperations,
        private readonly ApcuTagOperations $tagOperations,
        private readonly ApcuTopicOperations $topicOperations,
        private readonly ApcuMonitoringService $monitoringService,
        private readonly ApcuMaintenanceService $maintenanceService,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    // --- Basic CRUD Operations ---

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

    // --- Batch Operations ---

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

    // --- Atomic Operations ---

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        // APCu's native increment does not support custom TTLs or initial values in the same atomic operation.
        // The handler simulates this behavior.
        return $this->atomicOperations->increment($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        // APCu's native decrement does not support custom TTLs or initial values in the same atomic operation.
        // The handler simulates this behavior.
        return $this->atomicOperations->decrement($key, $step);
    }

    public function add(string $key, mixed $value): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('add')
        );

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
        iterable $tags
    ): bool {
        return $this->tagOperations->setWithTags($key, $value, $tags);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getByTags(iterable $tags): iterable
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

    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->topicOperations->subscribeTo($key, $topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        $topics = [];
        $topicPrefix = $this->config->getTopicIndexPrefix();

        // Scan through topic indices to find subscriptions for this key
        $iterator = new \APCUIterator('/^' . preg_quote($topicPrefix, '/') . '/');
        foreach ($iterator as $topic => $data) {
            if (is_array($data) && in_array($this->config->keyPrefix . $key, $data, true)) {
                $topics[] = substr($topic, strlen($topicPrefix));
            }
        }

        return $topics;
    }

    /**
     * FIXED: Implemented missing getSubscribersForTopic method.
     */
    public function getSubscribersForTopic(string $topic): array
    {
        $topicKey = $this->config->getTopicIndexPrefix() . $topic;
        $success = false;
        $subscribers = apcu_fetch($topicKey, $success);

        if (!$success || !is_array($subscribers)) {
            return [];
        }

        // Remove key prefix to return clean keys
        $prefixLength = strlen($this->config->keyPrefix);

        return array_map(
            fn ($key) => substr($key, $prefixLength),
            $subscribers
        );
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
