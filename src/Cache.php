<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Operation\AdvancedCacheOperations;
use KaririCode\Cache\Operation\BasicCacheOperations;
use KaririCode\Cache\Operation\TaggedCacheOperations;
use KaririCode\Cache\Operation\TopicCacheOperations;
use KaririCode\Cache\ValueObject\Tag;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A comprehensive cache component acting as a facade for various cache operations.
 * It follows the Single Responsibility Principle by delegating tasks to specialized
 * operation handlers, ensuring a clean and maintainable architecture.
 * This class implements the primary Cache contract.
 */
final class Cache implements CacheContract
{
    private readonly BasicCacheOperations $basic;
    private readonly TaggedCacheOperations $tagged;
    private readonly TopicCacheOperations $topic;
    private readonly AdvancedCacheOperations $advanced;

    /**
     * @param LayeredCacheContract $layeredCache the underlying layered cache pool
     * @param CacheConfig $config the cache configuration
     * @param CacheSerializer|null $serializer the serializer for cache values
     * @param LoggerInterface|null $logger a PSR-3 logger
     * @param EventDispatcherInterface|null $eventDispatcher a PSR-14 event dispatcher
     */
    public function __construct(
        LayeredCacheContract $layeredCache,
        CacheConfig $config,
        ?CacheSerializer $serializer = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        private readonly string $name = 'default'
    ) {
        $logger = $logger ?? new NullLogger();

        $this->basic = new BasicCacheOperations(
            $layeredCache,
            $serializer,
            $logger,
            $eventDispatcher
        );

        $this->tagged = new TaggedCacheOperations(
            $layeredCache,
            $logger,
            $eventDispatcher
        );

        $this->topic = new TopicCacheOperations(
            $layeredCache,
            $logger,
            $eventDispatcher
        );

        $this->advanced = new AdvancedCacheOperations(
            $layeredCache,
            $serializer,
            $logger,
            $eventDispatcher,
            $config
        );
    }

    // Basic Cache Operations (PSR-16)

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->basic->get($key, $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->basic->set($key, $value, $ttl);
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

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->basic->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->basic->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->basic->deleteMultiple($keys);
    }

    // Tagged Cache Operations

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        return $this->tagged->setWithTags($key, $value, $tags, $ttl);
    }

    /**
     * @param Tag[] $tags an array of Tag objects
     *
     * @return bool true if the tags were successfully invalidated, false otherwise
     */
    public function invalidateTags(array $tags): bool
    {
        return $this->tagged->invalidateTags($tags);
    }

    /**
     * @param Tag[] $tags
     */
    public function getByTags(array $tags): array
    {
        return $this->tagged->getByTags($tags);
    }

    public function getTags(): array
    {
        return $this->tagged->getAllTags();
    }

    // Topic Cache Operations

    public function subscribeTo(string $key, array $topics): void
    {
        $this->topic->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->topic->invalidateTopics($topics);
    }

    public function unsubscribeFrom(string $key, array $topics): void
    {
        $this->topic->unsubscribeFrom($key, $topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        return $this->topic->getSubscribedTopics($key);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topic->getSubscribersForTopic($topic);
    }

    // Advanced Cache Operations

    public function getWithCallback(string $key, ?callable $callback = null, \DateInterval|int|null $ttl = null, array $tags = []): mixed
    {
        if (null === $callback) {
            return $this->basic->get($key);
        }

        return $this->advanced->getWithCallback($key, $callback, $ttl, $tags);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->advanced->increment($key, $step, $initialValue, $ttl);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->advanced->decrement($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->advanced->add($key, $value, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->advanced->compareAndSwap($key, $expected, $value, $ttl);
    }

    public function warm(array $data, \DateInterval|int|null $ttl = null): void
    {
        $this->advanced->warmup($data, $ttl);
    }

    public function maintenance(array $options = []): bool
    {
        return $this->advanced->maintenance($options);
    }

    public function getMetrics(): CacheMetrics
    {
        return $this->advanced->getMetrics();
    }

    public function isHealthy(): bool
    {
        $healthCheck = $this->advanced->getHealthCheck();

        return ($healthCheck['status'] ?? 'unhealthy') === 'healthy';
    }

    public function getName(): string
    {
        return $this->name;
    }
}
