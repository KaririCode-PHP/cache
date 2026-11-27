<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Cache with tag-based invalidation: invalidating a tag makes every entry
 * written under an older version of that tag a miss.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): works over any CacheAdapter.
 * Spec V4: contract extending Cache.
 *
 * Preconditions: tags follow the native key rule.
 * Thread-safety: implementation-defined.
 *
 * Side effects: writes tag version keys besides the entry.
 *
 * Example:
 *     $cache->setTagged('user:42:profile', $profile, ['user:42'], 3600);
 *
 * References: ADR-012.
 *
 * @see https://symfony.com/doc/current/components/cache/cache_invalidation.html
 */
interface TagAwareCache extends Cache
{
    /**
     * @param list<string> $tags
     */
    public function setTagged(string $key, mixed $value, array $tags, ?int $ttl = null): bool;

    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): bool;
}
