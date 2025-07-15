<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

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
     * Invalidates all cache items associated with any of the specified tags.
     *
     * @param string[] $tags an array of tags to invalidate
     *
     * @return bool true if the invalidation was successful
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Gets all tags currently in use across all tag-aware layers.
     *
     * @return string[]
     */
    public function getTags(): array;
}
