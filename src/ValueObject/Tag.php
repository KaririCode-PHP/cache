<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * Tag Value Object.
 *
 * Represents a cache tag for grouping related cache entries.
 * Tags allow for batch invalidation of related cache items.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class Tag implements ImmutableValue
{
    /**
     * Maximum tag length.
     */
    private const MAX_LENGTH = 100;

    /**
     * Valid tag pattern.
     */
    private const VALID_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    /**
     * The tag value.
     */
    private readonly string $value;

    /**
     * @param string $value The tag value
     *
     * @throws InvalidArgumentException If tag is invalid
     */
    public function __construct(string $value)
    {
        $this->validate($value);
        $this->value = $this->normalize($value);
    }

    /**
     * Get the tag value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Convert to array.
     *
     * @return array{value: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    /**
     * Check equality with another tag.
     *
     * @param Tag $other The other tag
     */
    public function equals(Tag $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Create from string.
     *
     * @param string $value The tag value
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Validate tag value.
     *
     * @param string $value The value to validate
     *
     * @throws InvalidArgumentException If invalid
     */
    private function validate(string $value): void
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Tag cannot be empty');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Tag cannot exceed %d characters', self::MAX_LENGTH));
        }

        if (!preg_match(self::VALID_PATTERN, $value)) {
            throw new InvalidArgumentException('Tag contains invalid characters. Only alphanumeric, underscore, hyphen, and dot are allowed');
        }
    }

    /**
     * Normalize tag value.
     *
     * @param string $value The value to normalize
     */
    private function normalize(string $value): string
    {
        return trim(mb_strtolower($value));
    }
}

// ============================================================================
// Topic.php
// ============================================================================

namespace KaririCode\Cache\ValueObject;

use InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * Topic Value Object.
 *
 * Represents a cache topic for pub/sub functionality.
 * Topics allow for event-driven cache updates and invalidation.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class Topic implements ImmutableValue
{
    /**
     * Maximum topic length.
     */
    private const MAX_LENGTH = 150;

    /**
     * Valid topic pattern.
     */
    private const VALID_PATTERN = '/^[a-zA-Z0-9_\-\.\/]+$/';

    /**
     * Topic namespace separator.
     */
    private const NAMESPACE_SEPARATOR = '/';

    /**
     * The topic value.
     */
    private readonly string $value;

    /**
     * The topic namespace.
     */
    private readonly ?string $namespace;

    /**
     * The topic name.
     */
    private readonly string $name;

    /**
     * @param string $value The topic value
     *
     * @throws InvalidArgumentException If topic is invalid
     */
    public function __construct(string $value)
    {
        $this->validate($value);
        $this->value = $this->normalize($value);
        [$this->namespace, $this->name] = $this->parseNamespace($this->value);
    }

    /**
     * Get the topic value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the topic namespace.
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * Get the topic name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if topic has namespace.
     */
    public function hasNamespace(): bool
    {
        return null !== $this->namespace;
    }

    /**
     * Check if topic matches a pattern.
     *
     * @param string $pattern The pattern to match (supports wildcards)
     */
    public function matches(string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = '/^' . str_replace(
            ['*', '?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/';

        return (bool) preg_match($regex, $this->value);
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Convert to array.
     *
     * @return array{value: string, namespace: string|null, name: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'namespace' => $this->namespace,
            'name' => $this->name,
        ];
    }

    /**
     * Check equality with another topic.
     *
     * @param Topic $other The other topic
     */
    public function equals(Topic $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Create from string.
     *
     * @param string $value The topic value
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Create with namespace.
     *
     * @param string $namespace The namespace
     * @param string $name The topic name
     */
    public static function withNamespace(string $namespace, string $name): self
    {
        return new self($namespace . self::NAMESPACE_SEPARATOR . $name);
    }

    /**
     * Validate topic value.
     *
     * @param string $value The value to validate
     *
     * @throws InvalidArgumentException If invalid
     */
    private function validate(string $value): void
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Topic cannot be empty');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Topic cannot exceed %d characters', self::MAX_LENGTH));
        }

        if (!preg_match(self::VALID_PATTERN, $value)) {
            throw new InvalidArgumentException('Topic contains invalid characters. Only alphanumeric, underscore, hyphen, dot, and forward slash are allowed');
        }

        // Check for invalid namespace patterns
        if (str_starts_with($value, '/') || str_ends_with($value, '/')) {
            throw new InvalidArgumentException('Topic cannot start or end with a forward slash');
        }

        if (str_contains($value, '//')) {
            throw new InvalidArgumentException('Topic cannot contain consecutive forward slashes');
        }
    }

    /**
     * Normalize topic value.
     *
     * @param string $value The value to normalize
     */
    private function normalize(string $value): string
    {
        return trim($value);
    }

    /**
     * Parse namespace from topic value.
     *
     * @param string $value The topic value
     *
     * @return array{0: string|null, 1: string} [namespace, name]
     */
    private function parseNamespace(string $value): array
    {
        $lastSlash = strrpos($value, self::NAMESPACE_SEPARATOR);

        if (false === $lastSlash) {
            return [null, $value];
        }

        $namespace = substr($value, 0, $lastSlash);
        $name = substr($value, $lastSlash + 1);

        return [$namespace, $name];
    }
}

// ============================================================================
// CacheKey.php
// ============================================================================

namespace KaririCode\Cache\ValueObject;

use InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * Cache Key Value Object.
 *
 * Represents a validated and normalized cache key.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class CacheKey implements ImmutableValue
{
    /**
     * Maximum key length.
     */
    private const MAX_LENGTH = 250;

    /**
     * PSR-6 reserved characters.
     */
    private const RESERVED_CHARACTERS = '{}()/\@:';

    /**
     * Valid key pattern.
     */
    private const VALID_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    /**
     * The key value.
     */
    private readonly string $value;

    /**
     * Optional namespace.
     */
    private readonly ?string $namespace;

    /**
     * @param string $value The key value
     * @param string|null $namespace Optional namespace
     *
     * @throws InvalidArgumentException If key is invalid
     */
    public function __construct(string $value, ?string $namespace = null)
    {
        $this->validate($value);

        if (null !== $namespace) {
            $this->validateNamespace($namespace);
        }

        $this->namespace = $namespace;
        $this->value = $this->buildKey($value, $namespace);
    }

    /**
     * Get the full key value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the namespace.
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * Get the key without namespace.
     */
    public function getKeyWithoutNamespace(): string
    {
        if (null === $this->namespace) {
            return $this->value;
        }

        $prefix = $this->namespace . ':';
        if (str_starts_with($this->value, $prefix)) {
            return substr($this->value, strlen($prefix));
        }

        return $this->value;
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Convert to array.
     *
     * @return array{value: string, namespace: string|null}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'namespace' => $this->namespace,
        ];
    }

    /**
     * Check equality with another key.
     *
     * @param CacheKey $other The other key
     */
    public function equals(CacheKey $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Create from string.
     *
     * @param string $value The key value
     */
    public static function fromString(string $value): self
    {
        // Try to parse namespace from key
        if (str_contains($value, ':')) {
            [$namespace, $key] = explode(':', $value, 2);

            return new self($key, $namespace);
        }

        return new self($value);
    }

    /**
     * Validate key value.
     *
     * @param string $value The value to validate
     *
     * @throws InvalidArgumentException If invalid
     */
    private function validate(string $value): void
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        // Check for reserved characters
        for ($i = 0; $i < strlen(self::RESERVED_CHARACTERS); ++$i) {
            if (str_contains($value, self::RESERVED_CHARACTERS[$i])) {
                throw new InvalidArgumentException(sprintf('Cache key contains reserved character "%s"', self::RESERVED_CHARACTERS[$i]));
            }
        }
    }

    /**
     * Validate namespace.
     *
     * @param string $namespace The namespace to validate
     *
     * @throws InvalidArgumentException If invalid
     */
    private function validateNamespace(string $namespace): void
    {
        if ('' === $namespace) {
            throw new InvalidArgumentException('Namespace cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $namespace)) {
            throw new InvalidArgumentException('Namespace contains invalid characters');
        }
    }

    /**
     * Build the full key.
     *
     * @param string $value The key value
     * @param string|null $namespace The namespace
     *
     * @throws InvalidArgumentException If resulting key is too long
     */
    private function buildKey(string $value, ?string $namespace): string
    {
        if (null === $namespace) {
            $key = $value;
        } else {
            $key = $namespace . ':' . $value;
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Cache key cannot exceed %d characters', self::MAX_LENGTH));
        }

        return $key;
    }
}
