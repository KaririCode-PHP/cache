<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * Defines the contract for cache components that support atomic Compare-And-Swap (CAS) operations.
 *
 * This allows for safe read-modify-write cycles by ensuring that a value is only
 * updated if it has not been modified since it was last read.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CompareAndSwapAware
{
    /**
     * Compares the current value of an item with an expected value and, if they match,
     * replaces the current value with a new one.
     *
     * @param string $key the unique cache key
     * @param mixed $expected The expected value. The operation will fail if the current value does not match this.
     * @param mixed $value the new value to store
     *
     * @return bool true if the value was successfully swapped, false otherwise
     */
    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool;
}
