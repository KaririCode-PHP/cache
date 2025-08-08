<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Comprehensive validation service for cache operations.
 *
 * This service encapsulates all validation rules for cache keys, values, TTLs, and tags,
 * ensuring consistency across the entire application and adhering to the DRY principle.
 * It follows SOLID principles, Clean Code practices, and provides semantic validation
 * methods with clear responsibility separation.
 *
 * Key Features:
 * - PSR-6 compliant key validation
 * - Comprehensive value validation with size limits
 * - TTL validation for various input types
 * - Tag validation with format checking
 * - Batch validation for multiple items
 * - Robust error handling with descriptive messages
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       https://www.php-fig.org/psr/psr-6/ PSR-6 Caching Interface
 * @see       https://github.com/php-fig/cache Cache Library Standards
 */
final class ValidationService
{
    /**
     * Maximum allowed length for cache keys according to PSR-6 recommendations.
     */
    private const MAX_KEY_LENGTH = 250;

    /**
     * Regular expression pattern for PSR-6 reserved characters in cache keys.
     */
    private const INVALID_KEY_CHARS_REGEX = '/[{}()\/@ ]/';

    /**
     * Maximum allowed size for cache values in bytes (2MB).
     */
    private const MAX_VALUE_SIZE_BYTES = 2 * 1024 * 1024;

    /**
     * Maximum TTL in seconds (30 days).
     */
    private const MAX_TTL_SECONDS = 30 * 24 * 3600;

    /**
     * Validates a single cache key according to PSR-6 standards.
     *
     * This method performs comprehensive validation including emptiness,
     * length limits, and character restrictions as defined by PSR-6.
     *
     * @param string $key The cache key to validate
     *
     * @throws InvalidArgumentException When the key is invalid
     *
     * @see https://www.php-fig.org/psr/psr-6/#definitions PSR-6 Key Definition
     */
    public function validateKey(string $key): void
    {
        $this->ensureKeyIsNotEmpty($key);
        $this->ensureKeyIsWithinLengthLimits($key);
        $this->ensureKeyContainsValidCharacters($key);
    }

    /**
     * Validates multiple cache keys in an iterable collection.
     *
     * This method ensures all keys in the collection are strings and
     * individually valid according to PSR-6 standards.
     *
     * @param iterable<mixed> $keys Collection of keys to validate
     *
     * @throws InvalidArgumentException When any key is invalid
     */
    public function validateKeys(iterable $keys): void
    {
        foreach ($keys as $key) {
            $this->ensureValueIsString($key, 'All keys in an iterable must be strings.');
            $this->validateKey($key);
        }
    }

    /**
     * Validates a cache value for serializability and size constraints.
     *
     * This method checks if the value can be safely cached by verifying
     * serializability and ensuring it doesn't exceed size limits.
     *
     * @param mixed $value The value to validate
     *
     * @throws InvalidArgumentException When the value cannot be cached
     */
    public function validateValue(mixed $value): void
    {
        $this->ensureValueIsNotResource($value);
        $this->ensureValueIsSerializable($value);
        $this->ensureValueIsWithinSizeLimit($value);
    }

    /**
     * Validates multiple key-value pairs for cache operations.
     *
     * This method validates both keys and their corresponding values
     * in a single operation, useful for batch cache operations.
     *
     * @param iterable<string, mixed> $values Key-value pairs to validate
     *
     * @throws InvalidArgumentException When any key or value is invalid
     */
    public function validateMultipleValues(iterable $values): void
    {
        foreach ($values as $key => $value) {
            $this->validateKey((string) $key);
            $this->validateValue($value);
        }
    }

    /**
     * Validates TTL (Time To Live) values for cache operations.
     *
     * Accepts null (no expiration), positive integers (seconds), or DateInterval objects.
     * Ensures TTL values are within reasonable bounds to prevent overflow issues.
     *
     * @param \DateInterval|int|null $ttl Time to live specification
     *
     * @throws InvalidArgumentException When TTL is invalid
     */
    public function validateTtl(\DateInterval|int|null $ttl): void
    {
        if (null === $ttl) {
            return;
        }

        if (is_int($ttl)) {
            $this->ensureTtlIsNotNegative($ttl);
            $this->ensureTtlIsWithinLimits($ttl);
        }

        if ($ttl instanceof \DateInterval) {
            $this->ensureDateIntervalIsValid($ttl);
        }
    }

    /**
     * Validates cache tags for tagging operations.
     *
     * This method ensures all tags are either strings or Tag objects
     * and meet the format requirements for cache tagging.
     *
     * @param iterable<string|Tag> $tags Collection of tags to validate
     *
     * @throws InvalidArgumentException When any tag is invalid
     */
    public function validateTags(iterable $tags): void
    {
        foreach ($tags as $tag) {
            $this->ensureTagHasValidType($tag);

            if (is_string($tag)) {
                $this->ensureTagIsNotEmpty($tag);
                $this->ensureTagHasValidFormat($tag);
            }
        }
    }

    private function ensureKeyIsNotEmpty(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }
    }

    private function ensureKeyIsWithinLengthLimits(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
    }

    private function ensureKeyContainsValidCharacters(string $key): void
    {
        if (preg_match(self::INVALID_KEY_CHARS_REGEX, $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }
    }

    private function ensureValueIsString(mixed $value, string $message): void
    {
        if (!is_string($value)) {
            throw InvalidArgumentException::invalidKey($message);
        }
    }

    private function ensureValueIsNotResource(mixed $value): void
    {
        if (is_resource($value)) {
            throw InvalidArgumentException::invalidValue('Resources cannot be cached.');
        }
    }

    private function ensureValueIsSerializable(mixed $value): void
    {
        try {
            serialize($value);
        } catch (\Throwable $e) {
            throw InvalidArgumentException::unserializableValue(get_debug_type($value));
        }
    }

    private function ensureValueIsWithinSizeLimit(mixed $value): void
    {
        $serializedSize = strlen(serialize($value));

        if ($serializedSize > self::MAX_VALUE_SIZE_BYTES) {
            throw InvalidArgumentException::invalidValue(sprintf('Value size (%d bytes) exceeds the maximum limit (%d bytes).', $serializedSize, self::MAX_VALUE_SIZE_BYTES));
        }
    }

    private function ensureTtlIsNotNegative(int $ttl): void
    {
        if ($ttl < 0) {
            throw InvalidArgumentException::invalidTtl('Integer TTL cannot be negative.');
        }
    }

    private function ensureTtlIsWithinLimits(int $ttl): void
    {
        if ($ttl > self::MAX_TTL_SECONDS) {
            throw InvalidArgumentException::invalidTtl(sprintf('TTL (%d seconds) exceeds maximum allowed (%d seconds).', $ttl, self::MAX_TTL_SECONDS));
        }
    }

    private function ensureDateIntervalIsValid(\DateInterval $ttl): void
    {
        // Convert DateInterval to seconds for validation
        $now = new \DateTime();
        $future = (clone $now)->add($ttl);
        $seconds = $future->getTimestamp() - $now->getTimestamp();

        if ($seconds < 0) {
            throw InvalidArgumentException::invalidTtl('DateInterval TTL cannot represent negative duration.');
        }

        $this->ensureTtlIsWithinLimits($seconds);
    }

    private function ensureTagHasValidType(mixed $tag): void
    {
        if (!is_string($tag) && !$tag instanceof Tag) {
            throw InvalidArgumentException::invalidTag(sprintf('Tags must be strings or Tag objects, %s given.', get_debug_type($tag)));
        }
    }

    private function ensureTagIsNotEmpty(string $tag): void
    {
        if (empty($tag)) {
            throw InvalidArgumentException::emptyTag();
        }
    }

    private function ensureTagHasValidFormat(string $tag): void
    {
        // Apply similar restrictions as cache keys
        if (preg_match(self::INVALID_KEY_CHARS_REGEX, $tag)) {
            throw InvalidArgumentException::invalidTagFormat($tag);
        }
    }
}
