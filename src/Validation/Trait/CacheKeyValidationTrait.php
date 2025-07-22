<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides cache key validation functionality.
 *
 * This trait implements the Single Responsibility Principle by focusing
 * solely on cache key validation logic. It can be reused across multiple
 * classes that need key validation, following DRY principles.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait CacheKeyValidationTrait
{
    private const MAX_KEY_LENGTH = 250;
    private const RESERVED_KEYS = ['__metadata__', '__locks__', '__stats__'];

    /**
     * Validates a single cache key with comprehensive checks.
     *
     * @throws InvalidArgumentException if the key is invalid
     */
    protected function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::invalidKey('Cache key cannot be empty');
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::invalidKey(sprintf('Cache key exceeds maximum length of %d characters', self::MAX_KEY_LENGTH));
        }

        if (in_array($key, self::RESERVED_KEYS, true)) {
            throw InvalidArgumentException::invalidKey(sprintf('Cache key "%s" is reserved and cannot be used', $key));
        }

        // Check for invalid characters that could cause issues
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw InvalidArgumentException::invalidKey('Cache key contains invalid control characters');
        }

        // Check for characters that might cause issues in various backends
        if (preg_match('/[<>:"\/\\|?*]/', $key)) {
            throw InvalidArgumentException::invalidKey('Cache key contains characters that might cause issues in some cache backends');
        }
    }

    /**
     * Validates multiple cache keys at once.
     *
     * @param array<string> $keys Array of cache keys
     *
     * @throws InvalidArgumentException if any key is invalid
     */
    protected function validateKeys(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        foreach ($keys as $index => $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKey(sprintf('Key at index %d must be a string, %s given', $index, get_debug_type($key)));
            }

            try {
                $this->validateKey($key);
            } catch (InvalidArgumentException $e) {
                throw InvalidArgumentException::invalidKey(sprintf('Invalid key at index %d: %s', $index, $e->getMessage()));
            }
        }
    }

    /**
     * Sanitizes a cache key to make it safe for all backends.
     */
    protected function sanitizeKey(string $key): string
    {
        // Remove control characters and problematic chars
        $sanitized = preg_replace('/[\x00-\x1F\x7F<>:"\/\\|?*]/', '_', $key);

        // Ensure it's not empty after sanitization
        if (empty($sanitized)) {
            $sanitized = 'sanitized_key';
        }

        // Ensure length limit
        if (strlen($sanitized) > self::MAX_KEY_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_KEY_LENGTH - 8) . '_' . substr(md5($key), 0, 7);
        }

        return $sanitized;
    }
}
