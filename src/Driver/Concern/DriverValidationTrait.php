<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Concern;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Trait para centralizar a lógica de validação para os drivers de cache.
 */
trait DriverValidationTrait
{
    // Constantes de validação centralizadas
    private const MAX_KEY_LENGTH = 250;
    private const MAX_VALUE_SIZE = 512 * 1024 * 1024; // 512MB (usando o maior limite como padrão)
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

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || '' === $tag) {
                throw InvalidArgumentException::invalidTag((string) $tag);
            }
        }
    }

    private function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic) || '' === $topic) {
                throw InvalidArgumentException::invalidTopic((string) $topic);
            }
        }
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Step cannot be zero');
        }
    }
}
