<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

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
interface LayeredCache extends CacheItemPool
{
    /**
     * Add a cache layer with a specific priority.
     *
     * @param CacheItemPool $pool the cache pool to add as a layer
     * @param int $priority the priority of the layer (higher numbers are checked first)
     */
    public function addLayer(CacheItemPool $pool, int $priority): void;

    /**
     * Get all configured cache layers.
     *
     * @return CacheItemPool[] an array of cache pools
     */
    public function getLayers(): array;
}
