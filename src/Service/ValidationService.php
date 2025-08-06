<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Single source of truth for all cache-related validation logic.
 * This service encapsulates all rules for keys, values, TTLs, and more, ensuring
 * consistency across the entire application and adhering to the DRY principle.
 */
final class ValidationService
{
    private const MAX_KEY_LENGTH = 250;
    private const INVALID_KEY_CHARS_REGEX = '/[{}()\/@:]/'; // PSR-6 reserved characters
    private const MAX_VALUE_SIZE_BYTES = 2 * 1024 * 1024; // 2MB
    private const MAX_TTL_SECONDS = 30 * 24 * 3600; // 30 days

    public function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::invalidKey('Cache key cannot be empty.');
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
        if (preg_match(self::INVALID_KEY_CHARS_REGEX, $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }
    }

    public function validateKeys(iterable $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKey('All keys in an iterable must be strings.');
            }
            $this->validateKey($key);
        }
    }

    public function validateValue(mixed $value): void
    {
        if (is_resource($value)) {
            throw InvalidArgumentException::invalidValue('Resources cannot be cached.');
        }
        try {
            // A simple check for size and serializability
            if (strlen(serialize($value)) > self::MAX_VALUE_SIZE_BYTES) {
                throw InvalidArgumentException::invalidValue('Value size exceeds the maximum limit.');
            }
        } catch (\Throwable $e) {
            throw InvalidArgumentException::unserializableValue(get_debug_type($value));
        }
    }

    public function validateMultipleValues(iterable $values): void
    {
        foreach ($values as $key => $value) {
            $this->validateKey((string) $key);
            $this->validateValue($value);
        }
    }

    public function validateTtl(\DateInterval|int|null $ttl): void
    {
        if (null === $ttl) {
            return;
        }
        if (is_int($ttl) && $ttl < 0) {
            throw InvalidArgumentException::invalidTtl('Integer TTL cannot be negative.');
        }
    }

    public function validateTags(iterable $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) && !$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag('Tags must be strings or Tag objects.');
            }
        }
    }

    public function validateTopics(iterable $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic) && !$topic instanceof Topic) {
                throw InvalidArgumentException::invalidTopic('Topics must be strings or Topic objects.');
            }
        }
    }
}
