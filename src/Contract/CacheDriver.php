<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Enhanced cache driver interface with atomic operations and tagging.
 */
interface CacheDriver
{
    /**
     * Get a value from cache.
     */
    public function get(string $key): mixed;

    /**
     * Set a value in cache.
     */
    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool;

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool;

    /**
     * Delete a key.
     */
    public function delete(string $key): bool;

    /**
     * Clear all cache.
     */
    public function clear(): bool;

    /**
     * Get multiple values from cache.
     *
     * @param string[] $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array;

    /**
     * Set multiple values in cache.
     *
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool;

    /**
     * Delete multiple keys.
     *
     * @param string[] $keys
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Atomic increment (optional).
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    /**
     * Add value only if key doesn't exist (optional).
     */
    public function add(string $key, mixed $value, $ttl = null): bool;

    /**
     * Invalidate by tags (optional).
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Subscribe to topics (optional).
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Invalidate by topics (optional).
     */
    public function invalidateTopics(array $topics): bool;
}
