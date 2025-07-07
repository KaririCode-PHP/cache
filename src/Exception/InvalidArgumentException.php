<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

/**
 * Invalid argument exception with specific validation errors.
 */
final class InvalidArgumentException extends CacheException
{
    public static function emptyKey(): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 10,
            'EMPTY_CACHE_KEY',
            'Cache key cannot be empty'
        );
    }

    public static function keyTooLong(string $key): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 11,
            'KEY_TOO_LONG',
            "Cache key is too long (max 250 characters): '{$key}'",
            null,
            ['key' => $key, 'length' => strlen($key)]
        );
    }

    public static function invalidKeyFormat(string $key): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 12,
            'INVALID_KEY_FORMAT',
            "Invalid cache key format: '{$key}'. Only alphanumeric characters, underscores, dots, colons and hyphens are allowed",
            null,
            ['key' => $key]
        );
    }

    public static function invalidTtl($ttl): static
    {
        $type = is_object($ttl) ? get_class($ttl) : gettype($ttl);

        return static::createException(
            static::ERROR_CODE_BASE + 13,
            'INVALID_TTL',
            "Invalid TTL value. Expected int, DateInterval or null, got {$type}",
            null,
            ['ttl' => $ttl, 'type' => $type]
        );
    }

    public static function invalidTag(string $tag): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 14,
            'INVALID_TAG',
            "Invalid cache tag: '{$tag}'",
            null,
            ['tag' => $tag]
        );
    }
}
