<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * A type-safe, immutable Value Object representing a cache topic.
 */
final class Topic implements ImmutableValue
{
    private const VALID_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';

    private readonly string $value;

    /**
     * @throws InvalidArgumentException if the topic value is invalid
     */
    public function __construct(string $value)
    {
        if (empty($value)) {
            throw InvalidArgumentException::invalidTopic('Topic cannot be empty.');
        }

        if (!preg_match(self::VALID_PATTERN, $value)) {
            throw InvalidArgumentException::invalidTopic("Topic contains invalid characters: '{$value}'");
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
