<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

/**
 * Invalid argument exception with specific validation errors.
 */
final class InvalidArgumentException extends AbstractException
{
    private const CODE_EMPTY_KEY = 1240;
    private const CODE_KEY_TOO_LONG = 1241;
    private const CODE_INVALID_KEY_FORMAT = 1242;
    private const CODE_INVALID_TTL = 1243;
    private const CODE_INVALID_TAG = 1244;
    private const CODE_INVALID_TOPIC = 1245;
    private const CODE_EMPTY_TAG_LIST = 1246;
    private const CODE_INVALID_SERIALIZER = 1247;
    private const CODE_INVALID_PRIORITY = 1248;
    private const CODE_INVALID_NAMESPACE = 1249;

    public static function emptyKey(): static
    {
        return static::createException(
            self::CODE_EMPTY_KEY,
            'EMPTY_CACHE_KEY',
            'Cache key cannot be empty'
        );
    }

    public static function keyTooLong(string $key, int $maxLength = 250): static
    {
        return static::createException(
            self::CODE_KEY_TOO_LONG,
            'KEY_TOO_LONG',
            "Cache key is too long (max {$maxLength} characters): '{$key}'"
        );
    }

    public static function invalidKeyFormat(string $key): static
    {
        return static::createException(
            self::CODE_INVALID_KEY_FORMAT,
            'INVALID_KEY_FORMAT',
            "Invalid cache key format: '{$key}'. Only alphanumeric characters, underscores, dots, colons and hyphens are allowed"
        );
    }

    public static function invalidTtl(mixed $ttl): static
    {
        $type = is_object($ttl) ? get_class($ttl) : gettype($ttl);

        return static::createException(
            self::CODE_INVALID_TTL,
            'INVALID_TTL',
            "Invalid TTL value. Expected int, DateInterval or null, got {$type}"
        );
    }

    public static function invalidTag(string $tag): static
    {
        return static::createException(
            self::CODE_INVALID_TAG,
            'INVALID_TAG',
            "Invalid cache tag: '{$tag}'"
        );
    }

    public static function invalidTopic(string $topic): static
    {
        return static::createException(
            self::CODE_INVALID_TOPIC,
            'INVALID_TOPIC',
            "Invalid cache topic: '{$topic}'"
        );
    }

    public static function emptyTagList(): static
    {
        return static::createException(
            self::CODE_EMPTY_TAG_LIST,
            'EMPTY_TAG_LIST',
            'Tag list cannot be empty'
        );
    }

    public static function invalidSerializer(string $serializer): static
    {
        return static::createException(
            self::CODE_INVALID_SERIALIZER,
            'INVALID_SERIALIZER',
            "Invalid serializer: '{$serializer}'"
        );
    }

    public static function invalidPriority(int $priority, int $min = 0, int $max = 100): static
    {
        return static::createException(
            self::CODE_INVALID_PRIORITY,
            'INVALID_PRIORITY',
            "Invalid priority: {$priority}. Must be between {$min} and {$max}"
        );
    }

    public static function invalidNamespace(string $namespace): static
    {
        return static::createException(
            self::CODE_INVALID_NAMESPACE,
            'INVALID_NAMESPACE',
            "Invalid cache namespace: '{$namespace}'"
        );
    }
}
