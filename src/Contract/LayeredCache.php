<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Layered cache interface for hierarchical caching.
 */
interface LayeredCache extends CacheItemPool
{
    /**
     * Add a cache layer with priority.
     */
    public function addLayer(CacheItemPool $pool, int $priority): void;

    /**
     * Get all configured layers.
     */
    public function getLayers(): array;
}
