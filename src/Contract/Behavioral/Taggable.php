<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

use KaririCode\Cache\ValueObject\Tag;

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
interface Taggable extends TaggablePool
{
    /**
     * Associates cache item with tags for grouped invalidation.
     *
     * @param string $key the unique cache key
     * @param mixed $value the value to cache
     * @param Tag[] $tags the tags to associate with this item
     * @param \DateInterval|int|null $ttl the time-to-live for the item
     *
     * @return bool true on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool;
}
