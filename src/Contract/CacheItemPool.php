<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Interface CacheItemPool.
 *
 * Extends the PSR-6 CacheItemPoolInterface to provide advanced operations. This interface
 * serves as the framework-interoperable entry point for cache interactions, operating on
 * CacheItem objects rather than direct values. It combines the standardization of PSR-6
 * with the rich features of the KaririCode component, such as atomic operations and
 * invalidation by tags or topics.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheItemPool extends CacheItemPoolInterface
{
    /**
     * Performs an atomic increment operation on a key.
     *
     * @param string $key the unique cache key
     * @param int $step the value to increment by
     * @param int $initialValue the initial value if the key does not exist
     * @param \DateInterval|int|null $ttl the TTL for the item if it's created
     *
     * @return int the new value after incrementing
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    /**
     * Adds a value to the cache only if the key doesn't already exist.
     *
     * @param string $key the unique cache key
     * @param mixed $value the value to store
     * @param \DateInterval|int|null $ttl the TTL for the item
     *
     * @return bool true if the item was added, false if the key already exists
     */
    public function add(string $key, mixed $value, $ttl = null): bool;

    /**
     * Invalidates all cache items associated with the specified tags.
     *
     * @param string[] $tags an array of tags to invalidate
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Subscribes a cache item to invalidation topics.
     *
     * @param string $key the unique cache key
     * @param string[] $topics the topics to subscribe to
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Triggers invalidation for all items subscribed to the specified topics.
     *
     * @param string[] $topics the topics to trigger invalidation for
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTopics(array $topics): bool;
}
