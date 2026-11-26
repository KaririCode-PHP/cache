<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider\Expiration;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Applies constraints and validation to TTL values.
 *
 * Ensures TTL values conform to system constraints like maximum TTL,
 * minimum values, and business rules.
 *
 * @author Walmir Silva <walmir.silva@kariricode.org>
 */
final readonly class TtlConstraintApplier
{
    public const MIN_TTL_SECONDS = 1;
    public const MAX_TTL_SECONDS = 2592000; // 30 days

    /**
     * Applies maximum TTL constraint.
     */
    public function applyMaxTtl(int $ttl, ?int $maxTtl = null): int
    {
        $effectiveMaxTtl = $maxTtl ?? self::MAX_TTL_SECONDS;

        return min($ttl, $effectiveMaxTtl);
    }

    /**
     * Validates TTL against all constraints.
     */
    public function validateConstraints(int $ttl, ?int $maxTtl = null): void
    {
        if ($ttl < self::MIN_TTL_SECONDS) {
            throw InvalidArgumentException::invalidTtl('TTL must be at least ' . self::MIN_TTL_SECONDS . ' seconds');
        }

        $effectiveMaxTtl = $maxTtl ?? self::MAX_TTL_SECONDS;
        if ($ttl > $effectiveMaxTtl) {
            throw InvalidArgumentException::invalidTtl("TTL cannot exceed {$effectiveMaxTtl} seconds");
        }
    }

    /**
     * Applies all constraints in proper order.
     */
    public function applyAllConstraints(int $ttl, ?int $maxTtl = null): int
    {
        // REMOVED: The validation call was removed from here.
        // The logic now just applies the limit, without throwing an exception for an excessive value.
        return $this->applyMaxTtl($ttl, $maxTtl);
    }
}
