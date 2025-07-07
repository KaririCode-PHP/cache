<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Enhanced cache pool interface with advanced operations.
 */
interface CacheItemPool extends PsrCacheItemPoolInterface
{
    /**
     * Atomic increment operation.
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    /**
     * Add value only if key doesn't exist.
     */
    public function add(string $key, mixed $value, $ttl = null): bool;

    /**
     * Invalidate items by tags.
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Subscribe cache item to topics.
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Invalidate items by topics.
     */
    public function invalidateTopics(array $topics): bool;
}
