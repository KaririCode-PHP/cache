<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * Taggable interface.
 *
 * This interface defines methods for tag-based cache management.
 * Tags allow grouping related cache items for efficient batch operations
 * and invalidation strategies.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface Taggable
{
    /**
     * Associates cache item with tags for grouped invalidation.
     *
     * @param string $key the unique cache key
     * @param mixed $value the value to cache
     * @param array $tags the tags to associate with this item
     * @param \DateInterval|int|null $ttl the time-to-live for the item
     *
     * @return bool true on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool;

    /**
     * Invalidates all cache items associated with the specified tags.
     *
     * @param array $tags the tags to invalidate
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Retrieves cache items by their associated tags.
     *
     * @param array $tags the tags to search for
     *
     * @return array<string, mixed> array of cache items indexed by their keys
     */
    public function getByTags(array $tags): array;

    /**
     * Lists all tags currently in use.
     *
     * @return array<string> an array of tag names
     */
    public function getTags(): array;
}
