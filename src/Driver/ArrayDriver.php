<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\Array\Operation\ArrayTagOperations;
use KaririCode\Cache\Driver\Array\Operation\ArrayTopicOperations;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Contract\DataStructure\Map;
use KaririCode\DataStructure\Map\HashMap;
use KaririCode\DataStructure\Set\TreeSet;

/**
 * Array-based cache driver implementing all cache capabilities.
 *
 * This driver provides in-memory caching using KaririCode DataStructure components
 * with full support for tags, topics, atomic operations, and proper TTL management
 * through ExpirationProvider. Perfect for testing, development, and request-scoped caching.
 *
 * Architecture:
 * - Uses HashMap for key-value storage and metadata
 * - Uses TreeSet for efficient tag and topic indexing
 * - Follows Clean Architecture principles
 * - Implements SOLID design patterns
 * - Uses Strategy Pattern for ExpirationProvider
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayDriver implements
    AtomicOperable,
    TaggableCacheDriver,
    TopicAwareCacheDriver,
    CompareAndSwapAware,
    Maintainable
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    private Map $storage;
    private Map $expirations;
    private Map $tagIndex;
    private Map $topicIndex;
    private ArrayTagOperations $tagOperations;
    private ArrayTopicOperations $topicOperations;

    public function __construct(
        private readonly ExpirationProvider $expirationProvider,
        ?Map $storage = null,
        ?Map $expirations = null,
        ?Map $tagIndex = null,
        ?Map $topicIndex = null
    ) {
        $this->storage = $storage ?? new HashMap();
        $this->expirations = $expirations ?? new HashMap();
        $this->tagIndex = $tagIndex ?? new HashMap();
        $this->topicIndex = $topicIndex ?? new HashMap();

        // Initialize operation handlers
        $removeCallback = fn (string $key) => $this->removeFromAllIndexes($key);

        $this->tagOperations = new ArrayTagOperations(
            $this->storage,
            $this->expirations,
            $this->tagIndex,
            $removeCallback,
            $this->expirationProvider
        );

        $this->topicOperations = new ArrayTopicOperations(
            $this->storage,
            $this->expirations,
            $this->topicIndex,
            $removeCallback,
            $this->expirationProvider
        );
    }

    // ========================================================================
    // Basic Cache Operations
    // ========================================================================

    public function get(string $key): mixed
    {
        $this->validateKey($key);

        if ($this->isExpired($key)) {
            $this->removeFromAllIndexes($key);

            return null;
        }

        return $this->storage->containsKey($key) ? $this->storage->get($key) : null;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $expiration = $this->resolveExpiration($key, $ttl, 'set');

        $this->storage->put($key, $value);

        if (null !== $expiration) {
            $this->expirations->put($key, $expiration->getTimestamp());
        } else {
            $this->expirations->remove($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        if ($this->isExpired($key)) {
            $this->removeFromAllIndexes($key);

            return false;
        }

        return $this->storage->containsKey($key);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->removeFromAllIndexes($key);

        return true;
    }

    public function clear(): bool
    {
        $this->storage->clear();
        $this->expirations->clear();
        $this->tagIndex->clear();
        $this->topicIndex->clear();

        return true;
    }

    // ========================================================================
    // Batch Operations
    // ========================================================================

    public function getMultiple(iterable $keys): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function setMultiple(iterable $values): bool
    {
        $expiration = $this->resolveExpiration('', null, 'setMultiple');
        $success = true;

        foreach ($values as $key => $value) {
            $this->validateKey($key);
            $this->validateValue($value);

            $this->storage->put($key, $value);

            if (null !== $expiration) {
                $this->expirations->put($key, $expiration->getTimestamp());
            } else {
                $this->expirations->remove($key);
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    // ========================================================================
    // Atomic Operations
    // ========================================================================

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $this->validateKey($key);

        $current = $this->get($key);
        $currentValue = is_numeric($current) ? (int) $current : $initialValue;
        $newValue = $currentValue + $step;

        $expiration = $this->resolveExpiration($key, null, 'increment');
        $this->storage->put($key, $newValue);

        if (null !== $expiration) {
            $this->expirations->put($key, $expiration->getTimestamp());
        }

        return $newValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        $this->validateKey($key);

        $current = $this->get($key);
        $currentValue = is_numeric($current) ? (int) $current : $initialValue;
        $newValue = $currentValue - $step;

        $expiration = $this->resolveExpiration($key, null, 'decrement');
        $this->storage->put($key, $newValue);

        if (null !== $expiration) {
            $this->expirations->put($key, $expiration->getTimestamp());
        }

        return $newValue;
    }

    public function add(string $key, mixed $value): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        if ($this->has($key)) {
            return false;
        }

        $expiration = $this->resolveExpiration($key, null, 'add');
        $this->storage->put($key, $value);

        if (null !== $expiration) {
            $this->expirations->put($key, $expiration->getTimestamp());
        }

        return true;
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        $this->validateKey($key);
        $this->validateValue($expected);
        $this->validateValue($value);

        $current = $this->get($key);
        if ($current !== $expected) {
            return false;
        }

        $expiration = $this->resolveExpiration($key, null, 'compareAndSwap');
        $this->storage->put($key, $value);

        if (null !== $expiration) {
            $this->expirations->put($key, $expiration->getTimestamp());
        }

        return true;
    }

    // ========================================================================
    // Tag Operations (TaggableCacheDriver)
    // ========================================================================

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // First set the value normally
        $this->storage->put($key, $value);

        // Calculate and set expiration
        $expiration = $this->resolveExpiration($key, null, 'setWithTags');
        if (null !== $expiration) {
            $this->expirations->put($key, $expiration->getTimestamp());
        }

        // Then handle tag associations
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

    public function getTags(): iterable
    {
        return $this->tagOperations->getTags();
    }

    // ========================================================================
    // Topic Operations (TopicAwareCacheDriver)
    // ========================================================================

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

    // ========================================================================
    // Additional Methods for backward compatibility
    // ========================================================================

    /**
     * Gets keys by tag (for backward compatibility).
     *
     * @param iterable<string|Tag> $tags
     *
     * @return array<string>
     */
    public function getKeysByTag(iterable $tags): array
    {
        return $this->tagOperations->getKeysByTag($tags);
    }

    // ========================================================================
    // Driver Management and Statistics
    // ========================================================================

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
            CacheCapabilities::TOPICS,
        ];
    }

    public function maintenance(array $options = []): bool
    {
        $this->cleanupExpired();

        return true;
    }

    /**
     * Cleans up all expired entries.
     */
    public function cleanupExpired(): void
    {
        $currentTime = time();
        $expiredKeys = [];

        foreach ($this->expirations->keys() as $key) {
            $expiration = $this->expirations->get($key);
            if (is_int($expiration) && $expiration <= $currentTime) {
                $expiredKeys[] = $key;
            }
        }

        foreach ($expiredKeys as $key) {
            $this->removeFromAllIndexes($key);
        }
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Checks if a key is expired.
     */
    private function isExpired(string $key): bool
    {
        if (!$this->expirations->containsKey($key)) {
            return false;
        }

        $expiration = $this->expirations->get($key);

        return is_int($expiration) && $expiration < time();
    }

    /**
     * Removes a key from all indexes (storage, expiration, tags, topics).
     */
    private function removeFromAllIndexes(string $key): void
    {
        $this->storage->remove($key);
        $this->expirations->remove($key);

        // Remove from tag associations
        foreach ($this->tagIndex->keys() as $tagName) {
            $keysSet = $this->tagIndex->get($tagName);
            if ($keysSet instanceof TreeSet && $keysSet->contains($key)) {
                $keysSet->remove($key);
                if ($keysSet->isEmpty()) {
                    $this->tagIndex->remove($tagName);
                }
            }
        }

        // Remove from topic subscriptions
        foreach ($this->topicIndex->keys() as $topicName) {
            $keysSet = $this->topicIndex->get($topicName);
            if ($keysSet instanceof TreeSet && $keysSet->contains($key)) {
                $keysSet->remove($key);
                if ($keysSet->isEmpty()) {
                    $this->topicIndex->remove($topicName);
                }
            }
        }
    }

    /**
     * Resolves expiration time using ExpirationProvider strategy.
     */
    private function resolveExpiration(string $key, \DateInterval|int|null $ttl, string $operation): ?\DateTimeInterface
    {
        // Handle explicit null (no expiration)
        if (null === $ttl) {
            // Use ExpirationProvider to get appropriate TTL
            $providerTtl = $this->expirationProvider->getTtlForOperation($operation);

            return $this->expirationProvider->calculateExpiration($providerTtl);
        }

        // Handle DateInterval
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        // Handle integer (seconds)
        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return null; // No expiration for non-positive values
            }

            return new \DateTimeImmutable("+{$ttl} seconds");
        }

        // Fallback to ExpirationProvider
        $providerTtl = $this->expirationProvider->getTtlForKey($key);

        return $this->expirationProvider->calculateExpiration($providerTtl);
    }
}
