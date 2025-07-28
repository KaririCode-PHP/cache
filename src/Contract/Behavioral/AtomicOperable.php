<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * AtomicOperable interface.
 *
 * This interface defines atomic operations for cache items.
 * Atomic operations ensure thread-safe modifications of cached values
 * without race conditions.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface AtomicOperable
{
    /**
     * Atomically increments the value of the specified key.
     *
     * @param string $key the unique cache key
     * @param int $step the increment step (default: 1)
     * @param int $initialValue the initial value if key doesn't exist (default: 0)
     * @param \DateInterval|int|null $ttl the time-to-live for the item
     *
     * @return int the new incremented value
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int;

    /**
     * Atomically decrements the value of the specified key.
     *
     * @param string $key the unique cache key
     * @param int $step the decrement step (default: 1)
     * @param int $initialValue the initial value if key doesn't exist (default: 0)
     * @param \DateInterval|int|null $ttl the time-to-live for the item
     *
     * @return int the new decremented value
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int;

    /**
     * Adds a cache item only if the key doesn't already exist.
     *
     * @param string $key the unique cache key
     * @param mixed $value the value to cache
     * @param \DateInterval|int|null $ttl the time-to-live for the item
     *
     * @return bool true if added successfully, false if key already exists
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool;
}
