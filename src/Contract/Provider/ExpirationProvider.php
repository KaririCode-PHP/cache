<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Provider;

use DateInterval;
use DateTimeInterface;

/**
 * ExpirationProvider Interface - Strategy Pattern for TTL Management.
 *
 * This interface defines the contract for providing expiration times for cache entries.
 * It follows the Strategy Pattern, allowing different expiration strategies to be
 * implemented and swapped at runtime without changing the cache driver code.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
interface ExpirationProvider
{
    public const MAX_TTL_SECONDS = 2592000; // 30 days
    public const MIN_TTL_SECONDS = 1;

    /**
     * Calculates the expiration time for a cache entry.
     *
     * This method takes various inputs and returns a concrete expiration time.
     * Implementations can use different strategies like fixed TTL, sliding window,
     * or pattern-based expiration.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $ttl The TTL value
     *                                                       - DateInterval: Relative time from now
     *                                                       - DateTimeInterface: Absolute expiration time
     *                                                       - int: Seconds from now
     *                                                       - null: Use default TTL
     * @param string|null $key Optional cache key for pattern-based TTL
     * @param string|null $operation Optional operation type (set, add, increment, etc.)
     * @param array<string, mixed> $context Additional context for decision making
     *
     * @return \DateTimeInterface|null The calculated expiration time, or null for no expiration
     */
    public function calculateExpiration(
        \DateInterval|\DateTimeInterface|int|null $ttl = null,
        ?string $key = null,
        ?string $operation = null,
        array $context = []
    ): ?\DateTimeInterface;

    /**
     * Gets the default TTL in seconds.
     *
     * @return int The default TTL in seconds
     */
    public function getDefaultTtl(): int;

    /**
     * Determines if a given expiration time has passed.
     *
     * @param \DateTimeInterface|null $expiration The expiration time to check
     *
     * @return bool True if expired or null, false otherwise
     */
    public function isExpired(?\DateTimeInterface $expiration): bool;

    /**
     * Converts various TTL formats to seconds.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $ttl The TTL value to convert
     *
     * @return int|null The TTL in seconds, or null for no expiration
     */
    public function toSeconds(\DateInterval|\DateTimeInterface|int|null $ttl): ?int;

    /**
     * Creates a DateTimeInterface from seconds.
     *
     * @param int $seconds Number of seconds from now
     *
     * @return \DateTimeInterface The calculated expiration time
     */
    public function fromSeconds(int $seconds): \DateTimeInterface;

    /**
     * Gets TTL for a specific operation type.
     *
     * @param string $operation The operation type (set, add, increment, etc.)
     *
     * @return int|null The TTL in seconds for this operation, or null to use default
     */
    public function getOperationTtl(string $operation): ?int;

    /**
     * Gets TTL for a key matching a specific pattern.
     *
     * @param string $key The cache key to check
     *
     * @return int|null The TTL in seconds for this key pattern, or null to use default
     */
    public function getKeyPatternTtl(string $key): ?int;

    /**
     * Checks if sliding expiration is enabled.
     *
     * Sliding expiration means the TTL is reset every time the item is accessed.
     *
     * @return bool True if sliding expiration is enabled
     */
    public function isSlidingExpiration(): bool;

    /**
     * Updates expiration time for sliding expiration.
     *
     * @param \DateTimeInterface $currentExpiration Current expiration time
     * @param string|null $key Optional cache key
     * @param array<string, mixed> $context Additional context
     *
     * @return \DateTimeInterface The updated expiration time
     */
    public function updateSlidingExpiration(
        \DateTimeInterface $currentExpiration,
        ?string $key = null,
        array $context = []
    ): \DateTimeInterface;

    /**
     * Gets configuration for this provider.
     *
     * @return array<string, mixed> Provider configuration
     */
    public function getConfiguration(): array;

    /**
     * Validates if a TTL value is acceptable.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $ttl The TTL to validate
     *
     * @throws \InvalidArgumentException If TTL is invalid
     *
     * @return bool True if valid
     */
    public function validateTtl(\DateInterval|\DateTimeInterface|int|null $ttl): bool;
}
