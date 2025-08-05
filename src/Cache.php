<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Operation\AdvancedCacheOperations;
use KaririCode\Cache\Operation\BasicCacheOperations;
use KaririCode\Cache\Operation\TaggedCacheOperations;
use KaririCode\Cache\Operation\TopicCacheOperations;

final class Cache implements CacheContract
{
    public function __construct(
        private readonly BasicCacheOperations $basic,
        private readonly TaggedCacheOperations $tagged,
        private readonly TopicCacheOperations $topic,
        private readonly AdvancedCacheOperations $advanced,
        private readonly string $name = 'default'
    ) {
    }

    // Basic Cache Operations
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->basic->get($key, $default);
    }

    public function set(string $key, mixed $value): bool
    {
        return $this->basic->set($key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->basic->delete($key);
    }

    public function clear(): bool
    {
        return $this->basic->clear();
    }

    public function has(string $key): bool
    {
        return $this->basic->has($key);
    }

    public function getMultiple(iterable $keys): iterable
    {
        return $this->basic->getMultiple($keys);
    }

    public function setMultiple(iterable $values): bool
    {
        return $this->basic->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->basic->deleteMultiple($keys);
    }

    // Atomic Operations
    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->basic->increment($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->basic->decrement($key, $step, $initialValue);
    }

    public function add(string $key, mixed $value): bool
    {
        return $this->basic->add($key, $value);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        return $this->advanced->compareAndSwap($key, $expected, $value);
    }

    // Tagged Cache Operations
    public function setWithTags(string $key, mixed $value, iterable $tags = []): bool
    {
        return $this->tagged->setWithTags($key, $value, $tags);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagged->invalidateTags($tags);
    }

    public function getByTags(iterable $tags): iterable
    {
        return $this->tagged->getByTags($tags);
    }

    /**
     * {@inheritdoc}
     * Implements missing method from TaggablePool interface.
     */
    public function getTags(): iterable
    {
        return $this->tagged->getTags();
    }

    // Topic-based Operations
    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->topic->subscribeTo($key, $topics);
    }

    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        $this->topic->unsubscribeFrom($key, $topics);
    }

    public function invalidateTopics(iterable $topics): bool
    {
        return $this->topic->invalidateTopics($topics);
    }

    /**
     * {@inheritdoc}
     * Implements missing method from TopicSubscribable interface.
     */
    public function getSubscribedTopics(string $key): array
    {
        return $this->topic->getSubscribedTopics($key);
    }

    /**
     * {@inheritdoc}
     * Implements missing method from TopicSubscribable interface.
     */
    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topic->getSubscribersForTopic($topic);
    }

    // Advanced Operations
    public function getWithCallback(
        string $key,
        ?callable $callback = null,
        iterable $tags = []
    ): mixed {
        return $this->advanced->getWithCallback($key, $callback, $tags);
    }

    public function warm(iterable $data): void
    {
        $this->advanced->warm($data);
    }

    public function getMetrics(): CacheMetrics
    {
        return $this->advanced->getMetrics();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isHealthy(): bool
    {
        return $this->advanced->isHealthy();
    }

    public function maintenance(iterable $options = []): bool
    {
        $optionsArray = is_array($options) ? $options : iterator_to_array($options);

        return $this->advanced->maintenance($optionsArray);
    }
}
