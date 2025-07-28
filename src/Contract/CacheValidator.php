<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Contract\ImmutableValue;

/**
 * Interface CacheValidator.
 *
 * Defines the contract for cache validators.
 * Cache validators are responsible for validating cache keys, values,
 * and ensuring data integrity and security within the cache system.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheValidator
{
    /**
     * Validates a cache key.
     *
     * @param string $key the cache key to validate
     *
     * @return bool true if the key is valid, false otherwise
     */
    public function validateKey(string $key): bool;

    /**
     * Validates a cache value.
     *
     * @param mixed $value the cache value to validate
     *
     * @return bool true if the value is valid, false otherwise
     */
    public function validateValue(mixed $value): bool;

    /**
     * Sanitizes a cache key to ensure it meets requirements.
     *
     * @param string $key the cache key to sanitize
     *
     * @return string the sanitized cache key
     */
    public function sanitizeKey(string $key): string;

    /**
     * Gets the validation rules as an immutable object.
     *
     * @return ImmutableValue validation rules configuration
     */
    public function getValidationRules(): ImmutableValue;

    /**
     * Gets validation errors for a specific key.
     *
     * @param string $key the cache key to check
     *
     * @return array<string> an array of validation error messages
     */
    public function getValidationErrors(string $key): array;
}
