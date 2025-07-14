<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Contract\ImmutableValue;

/**
 * Interface CacheDriver.
 *
 * Defines the contract for cache drivers.
 * Cache drivers are responsible for implementing the actual storage
 * mechanism and communication with specific cache backends like Redis,
 * Memcached, filesystem, or in-memory arrays.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheDriver
{
    /**
     * Retrieves an item from the cache by its unique key.
     *
     * @param string $key the cache key to retrieve
     *
     * @return mixed the cached value or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Stores an item in the cache.
     *
     * @param string $key the cache key
     * @param mixed $value the value to store
     * @param \DateTimeInterface|null $expiration the expiration time
     *
     * @return bool true on success, false on failure
     */
    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key the cache key to check
     *
     * @return bool true if the item exists, false otherwise
     */
    public function has(string $key): bool;

    /**
     * Deletes an item from the cache.
     *
     * @param string $key the cache key to delete
     *
     * @return bool true on success, false on failure
     */
    public function delete(string $key): bool;

    /**
     * Clears all items from the cache.
     *
     * @return bool true on success, false on failure
     */
    public function clear(): bool;

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param array $keys the cache keys to retrieve
     *
     * @return array associative array of key-value pairs
     */
    public function getMultiple(array $keys): array;

    /**
     * Persists a set of key => value pairs in the cache.
     *
     * @param array $values associative array of key-value pairs
     * @param \DateTimeInterface|null $expiration the expiration time
     *
     * @return bool true on success, false on failure
     */
    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param array $keys the cache keys to delete
     *
     * @return bool true on success, false on failure
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Gets the supported capabilities of this driver.
     *
     * @return array list of supported capabilities
     */
    public function getCapabilities(): array;

    /**
     * Gets driver-specific statistics.
     *
     * @return ImmutableValue driver statistics as an immutable object
     */
    public function getStats(): ImmutableValue;
}
