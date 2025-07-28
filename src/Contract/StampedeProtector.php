<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface StampedeProtector.
 *
 * Defines the contract for implementing cache stampede protection (dogpiling).
 * This ensures that only one process generates a new cache value when an
 * item expires, while other processes receive a stale value.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface StampedeProtector
{
    /**
     * Retrieves an item, applying stampede protection if it's expired.
     *
     * @param string $key the cache key
     * @param callable $callback the function to generate the value
     * @param \DateInterval|int|null $ttl the time-to-live for the item
     *
     * @return mixed the cached or newly generated value
     */
    public function get(string $key, callable $callback, $ttl = null): mixed;

    /**
     * Checks if stampede protection is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Returns a new instance with stampede protection enabled.
     */
    public function enable(): self;

    /**
     * Returns a new instance with stampede protection disabled.
     */
    public function disable(): self;
}
