<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides cache value validation functionality.
 *
 * This trait handles validation of values to be stored in cache,
 * including serialization checks and size limits.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait CacheValueValidationTrait
{
    /**
     * Validates a cache value for serializability and safety.
     *
     * @throws InvalidArgumentException if the value cannot be cached
     */
    protected function validateValue(mixed $value): void
    {
        // Check for resources which cannot be serialized
        if (is_resource($value)) {
            throw InvalidArgumentException::invalidValue('Cache value cannot be a resource');
        }

        // Check for closures which cannot be serialized with native serializer
        if ($value instanceof \Closure) {
            throw InvalidArgumentException::invalidValue('Cache value cannot be a Closure');
        }

        // Try to serialize to check if value is serializable
        try {
            serialize($value);
        } catch (\Throwable $e) {
            throw InvalidArgumentException::invalidValue(sprintf('Cache value is not serializable: %s', $e->getMessage()));
        }
    }

    /**
     * Validates multiple cache values.
     *
     * @param array<string, mixed> $values Associative array of key-value pairs
     *
     * @throws InvalidArgumentException if any value is invalid
     */
    protected function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKey(sprintf('Cache key must be string, %s given', get_debug_type($key)));
            }

            try {
                $this->validateKey($key);
                $this->validateValue($value);
            } catch (InvalidArgumentException $e) {
                throw InvalidArgumentException::invalidValue(sprintf('Invalid value for key "%s": %s', $key, $e->getMessage()));
            }
        }
    }
}
