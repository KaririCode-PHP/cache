<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\Array\Operation\ArrayTagOperations;
use KaririCode\Cache\Driver\Array\Operation\ArrayTopicOperations;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Cache\ValueObject\Topic;

/**
 * An in-memory cache driver following the established architectural pattern.
 *
 * This driver has been refactored to maintain consistency with other drivers
 * by delegating specialized operations to dedicated operation classes.
 * It is request-scoped and does not persist data between requests.
 *
 * Key improvements:
 * - Follows SRP by delegating tag operations to ArrayTagOperations
 * - Maintains architectural consistency with other drivers
 * - Provides comprehensive validation and error handling
 * - Supports all cache capabilities with in-memory efficiency
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       ArrayTagOperations for tag-related operations
 * @see       ArrayTopicOperations for topic-related operations
 */
final class ArrayDriver implements
    CacheDriver,
    AtomicOperable,
    TaggableCacheDriver,
    TopicAwareCacheDriver
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;
    use AtomicOperationValidationTrait;
    use SerializationTrait;

    /** @var array<string, mixed> Main storage for cache values */
    private array $storage = [];

    /** @var array<string, int> Expiration timestamps for cache keys */
    private array $expirations = [];

    /** @var array<string, list<string>> Tag-to-keys mapping */
    private array $tagIndex = [];

    /** @var array<string, list<string>> Topic-to-keys mapping */
    private array $topicIndex = [];

    private ArrayTagOperations $tagOperations;
    private ArrayTopicOperations $topicOperations;

    public function __construct()
    {
        // Create operation handlers following the established pattern
        $this->tagOperations = new ArrayTagOperations(
            $this->storage,
            $this->expirations,
            $this->tagIndex,
            $this->removeFromIndexes(...)
        );

        $this->topicOperations = new ArrayTopicOperations(
            $this->storage,
            $this->expirations,
            $this->topicIndex,
            $this->removeFromIndexes(...)
        );
    }

    // ========================================================================
    // Basic Cache Operations (PSR-16 Compatible)
    // ========================================================================

    public function get(string $key): mixed
    {
        $this->validateKey($key);

        if (!isset($this->storage[$key]) || $this->isExpired($key)) {
            $this->delete($key);

            return null;
        }

        return $this->storage[$key];
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $this->storage[$key] = $value;

        if ($expiration) {
            $this->expirations[$key] = $expiration->getTimestamp();
        } else {
            unset($this->expirations[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        if (!isset($this->storage[$key])) {
            return false;
        }

        if ($this->isExpired($key)) {
            $this->delete($key);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        unset($this->storage[$key], $this->expirations[$key]);
        $this->removeFromIndexes($key);

        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        $this->expirations = [];
        $this->tagIndex = [];
        $this->topicIndex = [];

        return true;
    }

    // ========================================================================
    // Batch Operations
    // ========================================================================

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);

        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $expiration);
        }

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    // ========================================================================
    // Atomic Operations
    // ========================================================================

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $current = $this->get($key);
        if (!is_numeric($current)) {
            $current = $initialValue;
        }

        $newValue = (int) $current + $step;
        $this->set($key, $newValue, $this->calculateExpiration($ttl));

        return $newValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $current = $this->get($key);
        if (!is_numeric($current)) {
            $current = $initialValue;
        }

        $newValue = (int) $current - $step;
        $this->set($key, $newValue, $this->calculateExpiration($ttl));

        return $newValue;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);

        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $this->calculateExpiration($ttl));
    }

    // ========================================================================
    // Tag Operations (Delegated to ArrayTagOperations)
    // ========================================================================

    /**
     * Sets a cache item with associated tags.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param array<string|Tag> $tags Array of tags (strings or Tag objects)
     * @param \DateInterval|int|null $ttl Time to live
     *
     * @return bool True on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        return $this->tagOperations->setWithTags($key, $value, $tags, $ttl);
    }

    /**
     * Invalidates all cache items associated with the given tags.
     *
     * @param array<Tag> $tags Array of Tag objects to invalidate
     *
     * @return bool True if tags were successfully invalidated, false otherwise
     */
    public function invalidateTags(array $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * @param array<Tag> $tags Array of Tag objects
     *
     * @return array<string, mixed> Array of cache items indexed by their keys
     */
    public function getByTags(array $tags): array
    {
        return $this->tagOperations->getByTags($tags);
    }

    /**
     * Gets all tags currently in use.
     *
     * @return array<string> Array of tag names
     */
    public function getTags(): array
    {
        return $this->tagOperations->getTags();
    }

    // ========================================================================
    // Topic Operations (Delegated to ArrayTopicOperations)
    // ========================================================================

    /**
     * Subscribes a cache key to one or more topics.
     *
     * @param string $key The cache key to subscribe
     * @param array<Topic> $topics Array of Topic objects
     */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->topicOperations->subscribeTo($key, $topics);
    }

    /**
     * Invalidates all cache keys subscribed to the given topics.
     *
     * @param array<Topic> $topics Array of Topic objects to invalidate
     *
     * @return bool True on success, false on failure
     */
    public function invalidateTopics(array $topics): bool
    {
        return $this->topicOperations->invalidateTopics($topics);
    }

    // ========================================================================
    // Driver Information and Capabilities
    // ========================================================================

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
            CacheCapabilities::TOPICS,
        ];
    }

    public function getStats(): DriverStats
    {
        $memoryUsage = 0;
        foreach ($this->storage as $value) {
            $memoryUsage += strlen($this->serialize($value));
        }

        $stats = [
            'memory_usage' => $memoryUsage,
            'item_count' => count($this->storage),
            'tag_count' => count($this->tagIndex),
            'topic_count' => count($this->topicIndex),
            'expired_items' => $this->countExpiredItems(),
        ];

        return new MetricsSnapshot(
            'Array',
            $stats['memory_usage'],
            $stats['item_count'],
            phpversion(),
            $stats
        );
    }

    public function isHealthy(): bool
    {
        return true; // In-memory driver is always healthy
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Checks if a cache key has expired.
     */
    private function isExpired(string $key): bool
    {
        return isset($this->expirations[$key]) && $this->expirations[$key] < time();
    }

    /**
     * Removes a key from all indices (tags and topics).
     *
     * This method is passed as a callback to operation classes to maintain
     * index consistency when keys are deleted.
     */
    private function removeFromIndexes(string $key): void
    {
        // Remove from tag indices
        $this->tagOperations->removeKeyFromTagIndex($key);

        // Remove from topic indices
        foreach ($this->topicIndex as $topic => &$keys) {
            if (($index = array_search($key, $keys, true)) !== false) {
                unset($keys[$index]);
                $keys = array_values($keys); // Reindex

                if (empty($keys)) {
                    unset($this->topicIndex[$topic]);
                }
            }
        }
    }

    /**
     * Counts expired items for statistics.
     */
    private function countExpiredItems(): int
    {
        $count = 0;
        $currentTime = time();

        foreach ($this->expirations as $expiration) {
            if ($expiration < $currentTime) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Validates multiple key-value pairs for batch operations.
     *
     * @param array<string, mixed> $values
     */
    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->validateKey((string) $key);
            $this->validateValue($value);
        }
    }
}
