<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Operation\AdvancedCacheOperations;
use KaririCode\Cache\Operation\BasicCacheOperations;
use KaririCode\Cache\Operation\TaggedCacheOperations;
use KaririCode\Cache\Operation\TopicCacheOperations;
use KaririCode\Cache\Validation\Trait\UnifiedCacheValidationTrait;
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
    use UnifiedCacheValidationTrait;

    private readonly BasicCacheOperations $basicOperations;
    private readonly TaggedCacheOperations $taggedOperations;
    private readonly TopicCacheOperations $topicOperations;
    private readonly AdvancedCacheOperations $advancedOperations;
    private readonly string $name;

    /**
     * @param LayeredCacheContract $layeredCache the underlying layered cache pool
     * @param CacheSerializer|null $serializer the serializer for cache values
     * @param LoggerInterface|null $logger a PSR-3 logger
     * @param EventDispatcherInterface|null $eventDispatcher a PSR-14 event dispatcher
     * @param array<string, mixed> $config the cache configuration array
     */
    public function __construct(
        LayeredCacheContract $layeredCache,
        ?CacheSerializer $serializer = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        array $config = []
    ) {
        $logger = $logger ?? new NullLogger();
        $this->name = (string) ($config['name'] ?? 'default');

        $this->basicOperations = new BasicCacheOperations(
            $layeredCache,
            $serializer,
            $logger,
            $eventDispatcher
        );

        $this->taggedOperations = new TaggedCacheOperations(
            $layeredCache,
            $logger,
            $eventDispatcher
        );

        $this->topicOperations = new TopicCacheOperations(
            $layeredCache,
            $logger,
            $eventDispatcher
        );

        $this->advancedOperations = new AdvancedCacheOperations(
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
        $this->validateKey($key);

        return $this->basicOperations->get($key, $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateTtl($ttl);

        return $this->basicOperations->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->basicOperations->delete($key);
    }

    public function clear(): bool
    {
        return $this->basicOperations->clear();
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->basicOperations->has($key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->validateKeys($keysArray);

        return $this->basicOperations->getMultiple($keysArray, $default);
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKeyValuePairs($values);
        $this->validateTtl($ttl);

        return $this->basicOperations->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->validateKeys($keysArray);

        return $this->basicOperations->deleteMultiple($keysArray);
    }

    // Tagged Cache Operations

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateTags($tags);
        $this->validateTtl($ttl);

        return $this->taggedOperations->setWithTags($key, $value, $tags, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);

        return $this->taggedOperations->invalidateTags($tags);
    }

    public function getByTags(array $tags): array
    {
        $tagObjects = array_map(fn ($tag) => new Tag($tag), $tags);
        $this->validateTags($tagObjects);

        return $this->taggedOperations->getByTags($tags);
    }

    public function getTags(): array
    {
        return $this->taggedOperations->getAllTags();
    }

    // Topic Cache Operations

    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);
        $this->topicOperations->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);

        return $this->topicOperations->invalidateTopics($topics);
    }

    public function unsubscribeFrom(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);
        $this->topicOperations->unsubscribeFrom($key, $topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        $this->validateKey($key);

        return $this->topicOperations->getSubscribedTopics($key);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topicOperations->getSubscribersForTopic($topic);
    }

    // Advanced Cache Operations

    public function getWithCallback(string $key, ?callable $callback = null, \DateInterval|int|null $ttl = null, array $tags = []): mixed
    {
        if (null === $callback) {
            return $this->get($key);
        }

        $this->validateKey($key);
        $this->validateTtl($ttl);
        if (!empty($tags)) {
            $this->validateTags($tags);
        }

        return $this->advancedOperations->getWithCallback($key, $callback, $ttl, $tags);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateTtl($ttl);

        return $this->advancedOperations->increment($key, $step, $initialValue, $ttl);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateTtl($ttl);

        return $this->advancedOperations->decrement($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateTtl($ttl);

        return $this->advancedOperations->add($key, $value, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateTtl($ttl);

        return $this->advancedOperations->compareAndSwap($key, $expected, $value, $ttl);
    }

    public function warm(array $data, \DateInterval|int|null $ttl = null): void
    {
        $this->validateKeyValuePairs($data);
        $this->validateTtl($ttl);
        $this->advancedOperations->warmup($data, $ttl);
    }

    public function maintenance(array $options = []): bool
    {
        return $this->advancedOperations->maintenance($options);
    }

    public function getMetrics(): CacheMetrics
    {
        return $this->advancedOperations->getMetrics();
    }

    public function isHealthy(): bool
    {
        $healthCheck = $this->advancedOperations->getHealthCheck();

        return ($healthCheck['status'] ?? 'unhealthy') === 'healthy';
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function validateTtl(\DateInterval|int|null $ttl): void
    {
        if (null === $ttl) {
            return;
        }

        if (is_int($ttl) && $ttl < 0) {
            throw InvalidArgumentException::invalidTtl($ttl);
        }
    }

    /**
     * @param iterable<string, mixed> $values
     */
    private function validateKeyValuePairs(iterable $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat((string) $key);
            }
            $this->validateKey($key);
        }
    }
}
