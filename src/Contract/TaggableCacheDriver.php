<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\ValueObject\Tag;

/**
 * Extends the base CacheDriver to include support for tag-based operations.
 * Drivers implementing this interface can natively handle the association of keys
 * with tags, allowing for efficient invalidation and retrieval.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TaggableCacheDriver extends CacheDriver
{
    /**
     * Persists an item in the cache, associating it with one or more tags.
     *
     * @param string $key the cache item key
     * @param mixed $value the value to store
     * @param Tag[] $tags an array of Tag objects
     * @param \DateInterval|int|null $ttl the Time To Live for the item
     *
     * @return bool true on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool;

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * @param array<string> $tags an array of tag strings
     *
     * @return array<string, mixed> an associative array of key => value pairs
     */
    public function getByTags(array $tags): array;

    /**
     * Invalidates all cache items associated with one or more specified tags.
     *
     * @param array<string> $tags the tags to invalidate
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Gets all tags currently in use by the driver.
     *
     * @return string[] an array of tag names
     */
    public function getTags(): array;
}
