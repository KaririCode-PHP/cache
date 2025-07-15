<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation;

use KaririCode\Cache\Contract\CacheValidator;
use KaririCode\Contract\ImmutableValue;

/**
 * CacheKeyValidator - Must implement ImmutableValue.
 */
final class CacheKeyValidator implements CacheValidator, ImmutableValue
{
    private const MAX_KEY_LENGTH = 250;
    private const VALID_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';
    private const RESERVED_KEYS = ['', 'null', 'true', 'false'];

    public function __construct(
        private readonly array $rules = []
    ) {
    }

    public function validateValue(mixed $value): bool
    {
        return true; // No specific value validation for now
    }

    public function validateKey(string $key): bool
    {
        return empty($this->getValidationErrors($key));
    }

    public function sanitizeKey(string $key): string
    {
        // Remove or replace invalid characters
        $sanitized = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $key);

        // Trim to max length
        if (strlen($sanitized) > self::MAX_KEY_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_KEY_LENGTH - 32) . '_' . hash('md5', $key);
        }

        // Handle reserved keys
        if (in_array(strtolower($sanitized), self::RESERVED_KEYS)) {
            $sanitized = 'key_' . $sanitized;
        }

        return $sanitized;
    }

    public function getValidationErrors(string $key): array
    {
        $errors = [];

        if (empty($key)) {
            $errors[] = 'Key cannot be empty';
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            $errors[] = 'Key too long (max ' . self::MAX_KEY_LENGTH . ' characters)';
        }

        if (!preg_match(self::VALID_PATTERN, $key)) {
            $errors[] = 'Key contains invalid characters. Only alphanumeric, underscore, dot, colon, and hyphen are allowed';
        }

        if (in_array(strtolower($key), self::RESERVED_KEYS)) {
            $errors[] = 'Key is reserved and cannot be used';
        }

        return $errors;
    }

    public function getValidationRules(): ImmutableValue
    {
        return new ValidationRules([
            'max_key_length' => self::MAX_KEY_LENGTH,
            'valid_pattern' => self::VALID_PATTERN,
            'reserved_keys' => self::RESERVED_KEYS,
        ]);
    }
}
