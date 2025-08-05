<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

use KaririCode\Cache\ValueObject\Tag;

/**
 * Contract for a cache pool supporting tag-based operations.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TaggablePool
{
    /**
     * Invalidates all cache items associated with the given tags.
     *
     * @param iterable<string|Tag> $tags Tags to invalidate (can be strings or Tag objects)
     *
     * @return bool True on success, false on failure
     */
    public function invalidateTags(iterable $tags): bool;

    /**
     * Retrieves cache items associated with the given tags.
     *
     * @param iterable<string|Tag> $tags Tags to search for (can be strings or Tag objects)
     *
     * @return iterable<string, mixed> Cache items indexed by their keys
     */
    public function getByTags(iterable $tags): iterable;

    /**
     * Returns all tags currently in use in the cache pool.
     *
     * @return iterable<Tag> iterable of Tag objects
     */
    public function getTags(): iterable;
}
