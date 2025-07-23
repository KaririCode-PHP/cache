<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

/**
 * Provides a standardized way to calculate cache expiration.
 *
 * This trait unifies logic for handling TTL (Time To Live) in seconds
 * and absolute expiration dates (DateTimeInterface), following the DRY principle.
 */
trait ExpirationManagementTrait
{
    /**
     * Calculates TTL in seconds from a mixed value.
     *
     * @param \DateInterval|int|\DateTimeInterface|null $ttl TTL in PSR-16 format or an expiration date
     *
     * @return int TTL in seconds. Returns 0 if the item should not expire.
     */
    private function calculateTtlInSeconds(\DateInterval|int|\DateTimeInterface|null $ttl): int
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }

        if ($ttl instanceof \DateTimeInterface) {
            return max(0, $ttl->getTimestamp() - time());
        }

        if (is_int($ttl)) {
            return $ttl > 0 ? $ttl : 0;
        }

        return 0; // null or 0 means no expiration
    }

    /**
     * Calculates the absolute expiration date (DateTimeInterface) from a TTL value.
     *
     * @param \DateInterval|int|null $ttl TTL in PSR-16 format
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

        // For null, 0 or negative values, the item should not expire.
        return null;
    }
}
