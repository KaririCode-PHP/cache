<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Multi-tier cache contract: layers are read from fastest to slowest and a
 * hit is promoted to the faster layers.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): any CacheAdapter can be a layer.
 * Spec V4: contract extending Cache.
 *
 * Preconditions: layers are added fastest first.
 * Thread-safety: implementation-defined.
 *
 * Side effects: reads every layer until a hit; writes every layer.
 *
 * Example:
 *     $layered->addLayer(new ApcuAdapter('l1:'), 60)->addLayer($redisAdapter, 3600);
 *
 * References: SPEC-001 §8.
 *
 * @see https://en.wikipedia.org/wiki/Cache_hierarchy
 */
interface LayeredCache extends Cache
{
    /**
     * @param int|null $ttl Default TTL of the layer (seconds, null = no expiry).
     */
    public function addLayer(CacheAdapter $adapter, ?int $ttl = null): static;

    public function layerCount(): int;
}
