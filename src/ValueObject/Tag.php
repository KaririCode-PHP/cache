<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable Value Object representing a cache tag.
 *
 * This class ensures that a tag is always in a valid state by enforcing
 * validation rules upon creation. It is used to group related cache entries,
 * allowing for batch invalidation.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class Tag implements ImmutableValue
{
    private const MAX_LENGTH = 100;
    private const VALID_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    public readonly string $value;

    /**
     * The constructor is private to enforce object creation via the named constructor.
     * This ensures all tags are validated and normalized consistently.
     *
     * @param string $value the raw tag value
     */
    private function __construct(string $value)
    {
        $normalizedValue = $this->normalize($value);
        $this->validate($normalizedValue);
        $this->value = $normalizedValue;
    }

    /**
     * Creates a new Tag instance from a string.
     * This is the official factory method for creating Tag objects.
     *
     * @param string $value the tag value
     *
     * @throws InvalidArgumentException if the tag value is invalid
     *
     * @return self a new instance of the Tag
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Returns the string representation of the tag.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Compares this Tag with another to check for equality.
     *
     * @param Tag $other the other Tag to compare against
     *
     * @return bool true if the tags have the same value
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Validates the raw tag string against defined rules.
     *
     * @param string $value the value to validate
     *
     * @throws InvalidArgumentException if the value is not a valid tag
     */
    private function validate(string $value): void
    {
        if ('' === $value) {
            throw InvalidArgumentException::emptyTag();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidArgumentException::tagTooLong($value, self::MAX_LENGTH);
        }

        if (!preg_match(self::VALID_PATTERN, $value)) {
            throw InvalidArgumentException::invalidTagFormat($value);
        }
    }

    /**
     * Normalizes the tag value for consistent storage and comparison.
     *
     * @param string $value the raw value
     *
     * @return string the normalized value
     */
    private function normalize(string $value): string
    {
        return trim(mb_strtolower($value));
    }
}
