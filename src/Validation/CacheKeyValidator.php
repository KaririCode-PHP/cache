<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation;

use KaririCode\Cache\Contract\CacheValidator;
use KaririCode\Cache\ValueObject\ValidationPolicy;
use KaririCode\Contract\ImmutableValue;

/**
 * Optimized CacheKeyValidator with configurable rules and validation caching.
 *
 * This validator provides efficient cache key validation with:
 * - Configurable validation rules
 * - Internal validation result caching
 * - Early return optimizations
 * - Efficient string operations
 * - Proper immutable value pattern
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class CacheKeyValidator implements CacheValidator, ImmutableValue
{
    // Default validation rules
    private const DEFAULT_MAX_KEY_LENGTH = 250;
    private const DEFAULT_VALID_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';
    private const DEFAULT_RESERVED_KEYS = ['', 'null', 'true', 'false'];

    // Internal cache for validation results
    /** @var array<string, bool> */
    private array $validationCache = [];

    /** @var array<string, array<string>> */
    private array $errorCache = [];

    public function __construct(
        private readonly int $maxKeyLength = self::DEFAULT_MAX_KEY_LENGTH,
        private readonly string $validPattern = self::DEFAULT_VALID_PATTERN,
        private readonly array $reservedKeys = self::DEFAULT_RESERVED_KEYS,
        private readonly bool $cacheResults = true
    ) {
    }

    /**
     * Create validator with custom rules.
     */
    public static function withRules(
        int $maxKeyLength = self::DEFAULT_MAX_KEY_LENGTH,
        string $validPattern = self::DEFAULT_VALID_PATTERN,
        array $reservedKeys = self::DEFAULT_RESERVED_KEYS,
        bool $cacheResults = true
    ): self {
        return new self($maxKeyLength, $validPattern, $reservedKeys, $cacheResults);
    }

    /**
     * Create validator with relaxed rules for development.
     */
    public static function relaxed(): self
    {
        return new self(
            maxKeyLength: 500,
            validPattern: '/^[a-zA-Z0-9_.:\/-]+$/',
            reservedKeys: [''],
            cacheResults: true
        );
    }

    /**
     * Create validator with strict rules for production.
     */
    public static function strict(): self
    {
        return new self(
            maxKeyLength: 100,
            validPattern: '/^[a-zA-Z0-9_.-]+$/',
            reservedKeys: ['', 'null', 'true', 'false', 'undefined', 'NaN'],
            cacheResults: true
        );
    }

    public function validateValue(mixed $value): bool
    {
        // Basic value validation - can be extended
        return true;
    }

    public function validateKey(string $key): bool
    {
        // Check cache first if caching is enabled
        if ($this->cacheResults && isset($this->validationCache[$key])) {
            return $this->validationCache[$key];
        }

        $isValid = $this->performValidation($key);

        // Cache result if caching is enabled
        if ($this->cacheResults) {
            $this->validationCache[$key] = $isValid;
        }

        return $isValid;
    }

    /**
     * Perform actual validation with early returns for efficiency.
     */
    private function performValidation(string $key): bool
    {
        // Early return for empty key
        if ('' === $key) {
            return false;
        }

        // Early return for length check
        if (strlen($key) > $this->maxKeyLength) {
            return false;
        }

        // Early return for pattern check
        if (!preg_match($this->validPattern, $key)) {
            return false;
        }

        // Early return for reserved keys check
        if (in_array(strtolower($key), $this->reservedKeys, true)) {
            return false;
        }

        return true;
    }

    /**
     * Optimized key sanitization with minimal string operations.
     */
    public function sanitizeKey(string $key): string
    {
        if ('' === $key) {
            return 'empty_key';
        }

        // Single regex replace for efficiency
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $key);

        // Handle length efficiently
        if (strlen($sanitized) > $this->maxKeyLength) {
            $hashLength = 32; // MD5 hash length
            $maxPrefixLength = $this->maxKeyLength - $hashLength - 1; // -1 for underscore
            $sanitized = substr($sanitized, 0, $maxPrefixLength) . '_' . hash('md5', $key);
        }

        // Handle reserved keys with prefix
        $lowerKey = strtolower($sanitized);
        if (in_array($lowerKey, $this->reservedKeys, true)) {
            $sanitized = 'key_' . $sanitized;
        }

        return $sanitized;
    }

    /**
     * Get validation errors with caching.
     *
     * @return array<string>
     */
    public function getValidationErrors(string $key): array
    {
        // Check cache first if caching is enabled
        if ($this->cacheResults && isset($this->errorCache[$key])) {
            return $this->errorCache[$key];
        }

        $errors = $this->generateValidationErrors($key);

        // Cache result if caching is enabled
        if ($this->cacheResults) {
            $this->errorCache[$key] = $errors;
        }

        return $errors;
    }

    /**
     * Generate validation errors efficiently.
     *
     * @return array<string>
     */
    private function generateValidationErrors(string $key): array
    {
        $errors = [];

        // Check empty key
        if ('' === $key) {
            $errors[] = 'Key cannot be empty';

            return $errors; // Early return for empty key
        }

        // Check length
        $keyLength = strlen($key);
        if ($keyLength > $this->maxKeyLength) {
            $errors[] = "Key too long (max {$this->maxKeyLength} characters, got {$keyLength})";
        }

        // Check pattern
        if (!preg_match($this->validPattern, $key)) {
            $errors[] = 'Key contains invalid characters. Only alphanumeric, underscore, dot, colon, and hyphen are allowed';
        }

        // Check reserved keys
        if (in_array(strtolower($key), $this->reservedKeys, true)) {
            $errors[] = 'Key is reserved and cannot be used';
        }

        return $errors;
    }

    public function getValidationRules(): ImmutableValue
    {
        return new ValidationPolicy([
            'max_key_length' => $this->maxKeyLength,
            'valid_pattern' => $this->validPattern,
            'reserved_keys' => $this->reservedKeys,
            'cache_results' => $this->cacheResults,
        ]);
    }

    /**
     * Create new validator with different max key length.
     */
    public function withMaxKeyLength(int $maxKeyLength): self
    {
        return new self($maxKeyLength, $this->validPattern, $this->reservedKeys, $this->cacheResults);
    }

    /**
     * Create new validator with different valid pattern.
     */
    public function withValidPattern(string $validPattern): self
    {
        return new self($this->maxKeyLength, $validPattern, $this->reservedKeys, $this->cacheResults);
    }

    /**
     * Create new validator with different reserved keys.
     *
     * @param array<string> $reservedKeys
     */
    public function withReservedKeys(array $reservedKeys): self
    {
        return new self($this->maxKeyLength, $this->validPattern, $reservedKeys, $this->cacheResults);
    }

    /**
     * Create new validator with caching enabled/disabled.
     */
    public function withCaching(bool $cacheResults): self
    {
        return new self($this->maxKeyLength, $this->validPattern, $this->reservedKeys, $cacheResults);
    }

    /**
     * Clear validation cache.
     */
    public function clearCache(): void
    {
        $this->validationCache = [];
        $this->errorCache = [];
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        return [
            'validation_cache_size' => count($this->validationCache),
            'error_cache_size' => count($this->errorCache),
            'cache_enabled' => $this->cacheResults,
        ];
    }

    /**
     * Batch validate multiple keys efficiently.
     *
     * @param array<string> $keys
     *
     * @return array<string, bool>
     */
    public function validateKeys(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->validateKey($key);
        }

        return $results;
    }

    /**
     * Validate key with context for better error reporting.
     *
     * @return array{valid: bool, errors: array<string>, sanitized: string}
     */
    public function validateKeyWithContext(string $key): array
    {
        return [
            'valid' => $this->validateKey($key),
            'errors' => $this->getValidationErrors($key),
            'sanitized' => $this->sanitizeKey($key),
        ];
    }

    /**
     * Check if key needs sanitization.
     */
    public function needsSanitization(string $key): bool
    {
        return $this->sanitizeKey($key) !== $key;
    }

    /**
     * Get current validation rules as array.
     *
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        return [
            'max_key_length' => $this->maxKeyLength,
            'valid_pattern' => $this->validPattern,
            'reserved_keys' => $this->reservedKeys,
            'cache_results' => $this->cacheResults,
        ];
    }
}
