<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Cache Interface - Main contract for cache operations.
 *
 * This interface defines the fundamental cache operations that all
 * cache implementations must support. It follows PSR-6 and PSR-16
 * principles while providing a clean, modern API.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
interface Cache
{
    /**
     * Retrieve an item from the cache.
     *
     * @param string $key The unique key of the item to retrieve
     *
     * @return mixed The value stored in the cache, or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Store an item in the cache.
     *
     * @param string $key The key under which to store the value
     * @param mixed $value The value to store
     *
     * @return bool True on success, false on failure
     */
    public function set(string $key, mixed $value): bool;

    /**
     * Delete an item from the cache.
     *
     * @param string $key The key to delete
     *
     * @return bool True if the item was successfully removed or did not exist.
     *              False if there was an error.
     */
    public function delete(string $key): bool;

    /**
     * Clear all items from the cache.
     *
     * @return bool True on success, false on failure
     */
    public function clear(): bool;

    /**
     * Determine if an item exists in the cache.
     *
     * @param string $key The cache item key
     *
     * @return bool True if item exists in the cache, false otherwise
     */
    public function has(string $key): bool;
}
