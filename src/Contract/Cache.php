<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use Psr\SimpleCache\CacheInterface as PsrSimpleCacheInterface;

/**
 * Enhanced cache interface combining PSR-16 simplicity with advanced features.
 */
interface Cache extends PsrSimpleCacheInterface
{
    /**
     * PSR-16 get method.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Enhanced get method with callback and stampede protection.
     *
     * @param \DateInterval|int|null $ttl
     */
    public function getWithCallback(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed;

    /**
     * Simple get method (alias for get).
     */
    public function getSimple(string $key, mixed $default = null): mixed;

    /**
     * Set value with tags for grouped invalidation.
     *
     * @param \DateInterval|int|null $ttl
     */
    public function setWithTags(string $key, mixed $value, $ttl = null, array $tags = []): bool;

    /**
     * Atomic increment operation.
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    /**
     * Atomic decrement operation.
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    /**
     * Add value only if key doesn't exist.
     */
    public function add(string $key, mixed $value, $ttl = null): bool;

    /**
     * Invalidate all items with specified tags.
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Get cache metrics and statistics.
     */
    public function getMetrics(): array;

    /**
     * Access to advanced PSR-6 compatible API.
     */
    public function advanced(): CacheItemPool;

    /**
     * Warm cache with predefined data.
     */
    public function warm(array $data, $ttl = null): void;

    /**
     * Subscribe cache item to invalidation topics.
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Trigger invalidation event for topics.
     */
    public function invalidateTopics(array $topics): bool;
}
