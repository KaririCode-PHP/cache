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
     * @param list<string|Tag> $tags an array of Tag objects to invalidate
     * @param \DateInterval|int|null $ttl the Time To Live for the item
     *
     * @return bool true on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool;

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * @param list<string|Tag> $tags an array of Tag objects to invalidate
     *
     * @return array <string, mixed> an array of cache items indexed by their keys
     */
    public function getByTags(array $tags): array;

    /**
     * Invalidates all cache items associated with one or more specified tags.
     *
     * @param list<string|Tag> $tags an array of Tag objects to invalidate
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTags(array $tags): bool;

    public function getTags(): array;
}
