<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides a reusable method to validate a TTL (Time To Live) value.
 */
trait TtlValidationTrait
{
    /**
     * Validates a TTL value to ensure it's a valid PSR-16 type and not negative.
     *
     * @param \DateInterval|int|null $ttl the TTL value to validate
     *
     * @throws InvalidArgumentException if the TTL is an integer and is negative
     */
    private function validateTtl(\DateInterval|int|null $ttl): void
    {
        // The type hint (\DateInterval|int|null) already enforces the allowed types.
        // This check is specifically for the invalid state of a negative integer TTL.
        if (is_int($ttl) && $ttl < 0) {
            throw InvalidArgumentException::invalidTtl($ttl);
        }
    }
}
