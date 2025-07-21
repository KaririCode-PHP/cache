<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

/**
 * Provides a standardized way to calculate TTL in seconds.
 */
trait TtlCalculatorTrait
{
    private function calculateTtl(\DateInterval|int|\DateTimeInterface|null $ttl): int
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }
        if ($ttl instanceof \DateTimeInterface) {
            return $ttl->getTimestamp() - time();
        }
        if (is_int($ttl)) {
            return $ttl > 0 ? $ttl : 0;
        }

        return 0;
    }
}
