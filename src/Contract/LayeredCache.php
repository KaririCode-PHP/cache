<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;

/**
 * Interface LayeredCache.
 *
 * Defines the contract for a layered (hierarchical) cache pool.
 * It allows combining multiple cache pools (layers) with different priorities,
 * such as an in-memory cache backed by a Redis cache.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface LayeredCache extends CacheItemPool, Maintainable, Taggable, TopicSubscribable, CompareAndSwapAware
{
    /**
     * Adds a cache pool as a new layer with a specific priority.
     *
     * @param CacheItemPool $pool the cache pool to add
     * @param int $priority the priority of the layer (higher numbers are checked first)
     */
    public function addLayer(CacheItemPool $pool, int $priority): void;

    /**
     * Gets all configured cache layers, ordered by priority.
     *
     * @return CacheItemPool[]
     */
    public function getLayers(): iterable;
}
