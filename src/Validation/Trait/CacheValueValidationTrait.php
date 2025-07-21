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
    private const MAX_VALUE_SIZE = 512 * 1024 * 1024; // 512MB
    private const MAX_BATCH_SIZE = 1000;

    /**
     * Validates a cache value.
     *
     * @param mixed $value The value to validate
     *
     * @throws InvalidArgumentException if value cannot be cached
     */
    protected function validateValue(mixed $value): void
    {
        try {
            $serialized = serialize($value);
        } catch (\Throwable) {
            throw InvalidArgumentException::unserializableValue(get_debug_type($value));
        }

        if (strlen($serialized) > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidValue('Value too large: ' . strlen($serialized) . ' bytes (max: ' . self::MAX_VALUE_SIZE . ')');
        }
    }

    /**
     * Validates multiple key-value pairs.
     *
     * @param array<string, mixed> $values Array of key-value pairs
     *
     * @throws InvalidArgumentException if any value is invalid
     */
    protected function validateMultipleValues(array $values): void
    {
        if (count($values) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidValue('Too many values in batch: ' . count($values) . ' (max: ' . self::MAX_BATCH_SIZE . ')');
        }

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be a string, ' . gettype($key) . ' given');
            }
            $this->validateKey($key); // Assumes CacheKeyValidationTrait is also used
            $this->validateValue($value);
        }
    }

    /**
     * Validates a step value for increment/decrement operations.
     *
     * @param int $step The step value to validate
     *
     * @throws InvalidArgumentException if step is invalid
     */
    protected function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Step cannot be zero');
        }
    }
}
