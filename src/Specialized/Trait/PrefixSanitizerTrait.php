<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized\Trait;

/**
 * Provides a standardized way to sanitize cache key prefixes.
 */
trait PrefixSanitizerTrait
{
    private const MAX_KEY_LENGTH = 250;

    private function sanitizePrefix(string $prefix): string
    {
        $sanitized = (string) preg_replace('/[^\w\-\.\:]/', '_', $prefix);

        return substr($sanitized, 0, 50);
    }

    /**
     * Sanitizes a key for safe usage.
     *
     * @param string $key The key to sanitize
     *
     * @return string The sanitized key
     */
    protected function sanitizeKey(string $key): string
    {
        // Remove or replace invalid characters
        // Cast to string to satisfy static analysis, as preg_replace can return null on error.
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $key);

        // Trim to maximum length
        if (strlen($sanitized) > self::MAX_KEY_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_KEY_LENGTH);
        }

        // Ensure not empty after sanitization
        return $sanitized ?: 'default_key';
    }
}
