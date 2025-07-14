<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface AtomicCacheDriver.
 *
 * Extends the base CacheDriver to include support for atomic operations. Drivers implementing
 * this interface guarantee that operations like increment and "add-if-not-exists" are
 * concurrency-safe (thread-safe), preventing race conditions. Backends like Redis and
 * Memcached provide native commands for these operations.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface AtomicCacheDriver extends CacheDriver
{
    /**
     * Atomically increments the integer value of a key.
     *
     * @param string $key the unique cache key
     * @param int $step the value to increment by
     * @param int $initialValue the value to set if the key doesn't exist before incrementing
     * @param \DateInterval|int|null $ttl the Time To Live for the item
     *
     * @return int the new value after the increment
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    /**
     * Atomically adds an item to the cache only if the key doesn't already exist.
     *
     * @param string $key the cache key
     * @param mixed $value the value to store
     * @param \DateInterval|int|null $ttl the Time To Live for the item
     *
     * @return bool true if the item was set, false if the key already existed
     */
    public function add(string $key, mixed $value, $ttl = null): bool;
}
