<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * Cacheable interface.
 *
 * This interface defines the caching methods for various cache operations.
 * Implementers can cache, retrieve, and manage cached data with different
 * strategies and configurations.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface Cacheable
{
    /**
     * Retrieves an item from the cache by its unique key.
     *
     * @param string $key the unique cache key
     *
     * @return mixed the cached value or default if not found
     */
    public function get(string $key): mixed;

    /**
     * Persists data in the cache, uniquely referenced by a key.
     *
     * @param string $key the unique cache key
     * @param mixed $value the value to cache
     * @param \DateInterval|int|null $ttl the time-to-live for the cached item
     *
     * @return bool true on success, false on failure
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key the unique cache key
     *
     * @return bool true if the item exists in the cache, false otherwise
     */
    public function has(string $key): bool;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key the unique cache key
     *
     * @return bool true on success, false on failure
     */
    public function delete(string $key): bool;

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool true on success, false on failure
     */
    public function clear(): bool;
}
