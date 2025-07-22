<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

use KaririCode\Cache\ValueObject\Tag;

/**
 * Defines the contract for a cache pool that supports tag-based operations.
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
     * Invalidates all cache items associated with the specified tags.
     *
     * @param Tag[] $tags the tags to invalidate
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
