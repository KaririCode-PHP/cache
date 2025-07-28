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
     * @param mixed $default the default value to return if the key does not exist
     *
     * @return mixed the cached value or default if not found
     */
    public function get(string $key, mixed $default = null): mixed;

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

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys the cache keys to retrieve
     * @param mixed $default the default value for missing keys
     *
     * @return iterable<string, mixed> the retrieved values indexed by cache key
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * Persists a set of key => value pairs in the cache.
     *
     * @param iterable<string, mixed> $values the key-value pairs to store
     * @param \DateInterval|int|null $ttl the time-to-live for the cached items
     *
     * @return bool true on success, false on failure
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys the cache keys to delete
     *
     * @return bool true on success, false on failure
     */
    public function deleteMultiple(iterable $keys): bool;
}
