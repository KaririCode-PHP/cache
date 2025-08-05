<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Provider;

/**
 * Interface ExpirationProvider.
 * Defines methods for managing expiration times in cache operations.
 * This interface allows drivers to provide default TTLss, specific TTLs for operations,
 * and calculate expiration times based on provided TTLs.s.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface ExpirationProvider
{
    /**
     * Gets the default TTL (Time To Live) for cache entries.
     *
     * @return int default TTL in seconds
     */
    public function getDefaultTtl(): int;

    /**
     * Gets the TTL for a specific cache operation.
     *
     * @param string $operation the operation for which to get the TTL
     *
     * @return int TTL in seconds for the specified operation
     */
    public function getTtlForOperation(string $operation): int;

    /**
     * Gets the TTL for a specific cache key.
     *
     * @param string $key the cache key for which to get the TTL
     *
     * @return int TTL in seconds for the specified key
     */
    public function getTtlForKey(string $key): int;

    /**
     * Calculates the expiration time based on the provided TTL.
     *
     * @param int|null $ttl the TTL in seconds; if null, uses the default TTL
     *
     * @return \DateTimeInterface|null the calculated expiration time, or null if no expiration is set
     */
    public function calculateExpiration(?int $ttl = null): ?\DateTimeInterface;
}
