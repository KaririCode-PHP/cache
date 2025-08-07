<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable Value Object representing a cache topic.
 *
 * This class ensures that a topic is always in a valid state by enforcing
 * validation rules upon creation. It is used for pub/sub style cache
 * invalidation.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class Topic implements ImmutableValue
{
    private const MAX_LENGTH = 150;
    private const VALID_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';

    public readonly string $value;

    /**
     * The constructor is private to enforce object creation via the named constructor.
     *
     * @param string $value the raw topic value
     */
    private function __construct(string $value)
    {
        $normalizedValue = $this->normalize($value);
        $this->validate($normalizedValue);

        $this->value = $normalizedValue;
    }

    /**
     * Creates a new Topic instance from a string.
     *
     * @param string $value the topic value
     *
     * @throws InvalidArgumentException if the topic value is invalid
     *
     * @return self a new instance of the Topic
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Returns the string representation of the topic.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Compares this Topic with another to check for equality.
     *
     * @param Topic $other the other Topic to compare against
     *
     * @return bool true if the topics have the same value
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Validates the normalized topic string against defined rules.
     *
     * @param string $value the value to validate
     *
     * @throws InvalidArgumentException if the value is not a valid topic
     */
    private function validate(string $value): void
    {
        if ('' === $value) {
            throw InvalidArgumentException::emptyTopic();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidArgumentException::topicTooLong($value, self::MAX_LENGTH);
        }

        if (!preg_match(self::VALID_PATTERN, $value)) {
            throw InvalidArgumentException::invalidTopicFormat($value);
        }
    }

    /**
     * Normalizes the topic value for consistent storage and comparison.
     *
     * @param string $value the raw value
     *
     * @return string the normalized value
     */
    private function normalize(string $value): string
    {
        return trim($value);
    }
}
