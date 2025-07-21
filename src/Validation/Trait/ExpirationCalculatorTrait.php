<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

/**
 * Provides a standardized way to calculate an absolute expiration timestamp.
 * This trait acts as a bridge between PSR-16 TTL formats (int, DateInterval)
 * and the internal DateTimeInterface format used by drivers.
 */
trait ExpirationCalculatorTrait
{
    /**
     * Calculates the absolute expiration time from a TTL value.
     *
     * @param \DateInterval|int|null $ttl the TTL in a PSR-16 compatible format
     *
     * @return \DateTimeInterface|null an absolute expiration date, or null for indefinite cache
     */
    private function calculateExpiration(\DateInterval|int|null $ttl): ?\DateTimeInterface
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }
        if (is_int($ttl) && $ttl > 0) {
            return new \DateTimeImmutable('+' . $ttl . ' seconds');
        }

        // For null or 0, it means the item should not expire.
        return null;
    }
}
