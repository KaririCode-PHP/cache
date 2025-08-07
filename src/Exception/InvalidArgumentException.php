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
    private const CODE_INVALID_VALUE = 1250;
    private const CODE_INVALID_CONFIGURATION = 1251;
    private const CODE_INVALID_STEP = 1252;

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

    public static function invalidTtl(string $message): static
    {
        return static::createException(
            self::CODE_INVALID_TTL,
            'INVALID_TTL',
            $message
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

    public static function emptyTag(): static
    {
        return static::createException(
            self::CODE_INVALID_TAG,
            'EMPTY_TAG',
            'Tag cannot be empty.'
        );
    }

    public static function tagTooLong(string $tag, int $maxLength): static
    {
        return static::createException(
            self::CODE_INVALID_TAG,
            'TAG_TOO_LONG',
            sprintf('Tag cannot exceed %d characters. Current length: %d.', $maxLength, strlen($tag))
        );
    }

    public static function invalidTagFormat(string $tag): static
    {
        return static::createException(
            self::CODE_INVALID_TAG,
            'INVALID_TAG_FORMAT',
            "Invalid cache tag format: '{$tag}'. Only alphanumeric characters, underscores, dots, colons and hyphens are allowed"
        );
    }

    public static function invalidTopic(string $reason): static
    {
        return static::createException(
            self::CODE_INVALID_TOPIC,
            'INVALID_TOPIC',
            "Invalid cache topic: {$reason}"
        );
    }

    public static function emptyTopic(): static
    {
        return static::createException(
            self::CODE_INVALID_TOPIC,
            'EMPTY_TOPIC',
            'Topic cannot be empty.'
        );
    }

    public static function topicTooLong(
        string $topic,
        int $maxLength
    ): static {
        return static::createException(
            self::CODE_INVALID_TOPIC,
            'TOPIC_TOO_LONG',
            sprintf('Topic cannot exceed %d characters. Current length: %d.', $maxLength, mb_strlen($topic))
        );
    }

    public static function invalidTopicFormat(string $topic): static
    {
        return static::createException(
            self::CODE_INVALID_TOPIC,
            'INVALID_TOPIC_FORMAT',
            "Invalid cache topic format: '{$topic}'. Only alphanumeric characters, underscores, dots, colons, and hyphens are allowed."
        );
    }

    public static function forInvalidStep(int $step): static
    {
        return static::createException(
            self::CODE_INVALID_STEP,
            'INVALID_STEP',
            "Invalid step value: '{$step}'"
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

    public static function unserializableValue(string $type): static
    {
        return static::createException(
            self::CODE_INVALID_VALUE,
            'UNSERIALIZABLE_VALUE',
            "The provided value of type '{$type}' cannot be serialized."
        );
    }

    public static function invalidConfiguration(string $message): static
    {
        return static::createException(
            self::CODE_INVALID_CONFIGURATION,
            'INVALID_CONFIGURATION',
            $message
        );
    }

    public static function invalidKey(string $reason): static
    {
        return static::createException(
            self::CODE_INVALID_KEY_FORMAT,
            'INVALID_CACHE_KEY',
            $reason
        );
    }

    public static function invalidValue(string $reason, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_INVALID_VALUE,
            'INVALID_VALUE',
            $reason,
            $previous
        );
    }

    public static function reservedKey(string $key): static
    {
        return static::createException(
            self::CODE_INVALID_KEY_FORMAT,
            'RESERVED_KEY',
            "The key '{$key}' is reserved and cannot be used"
        );
    }
}
