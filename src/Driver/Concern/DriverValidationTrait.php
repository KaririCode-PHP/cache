<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Concern;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Cache\ValueObject\Topic;

/**
 * DriverValidationTrait provides validation methods for cache drivers.
 *
 * This trait includes methods to validate cache keys, values, and batch operations,
 * ensuring compliance with defined constraints.
 *
 * @category  Cache
 */
trait DriverValidationTrait
{
    private const MAX_KEY_LENGTH = 250;
    private const MAX_VALUE_SIZE = 512 * 1024 * 1024; // 512MB
    private const MAX_BATCH_SIZE = 1000;

    private function validateKey(string $key): void
    {
        if ('' === $key) {
            throw InvalidArgumentException::emptyKey();
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
    }

    private function validateKeys(array $keys): void
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidValue('Too many keys in batch: ' . count($keys));
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be a string');
            }
            $this->validateKey($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidValue('Value too large: ' . strlen($serialized) . ' bytes');
        }
    }

    private function validateMultipleValues(array $values): void
    {
        if (count($values) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidValue('Too many values in batch: ' . count($values));
        }
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be a string');
            }
            $this->validateKey($key);
            $this->validateValue($value);
        }
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Step cannot be zero');
        }
    }

    /**
     * @param array<Tag> $tags
     */
    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag(get_debug_type($tag));
            }
        }
    }

    /**
     * @param array<Topic> $topics
     */
    private function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!$topic instanceof Topic) {
                throw InvalidArgumentException::invalidTopic(get_debug_type($topic));
            }
        }
    }
}
