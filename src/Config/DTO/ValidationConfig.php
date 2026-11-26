<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that encapsulates validation rules for cache keys and values.
 *
 * It defines constraints such as maximum lengths, sizes, and character patterns
 * to ensure data integrity and compliance with PSR-6 standards.
 */
final class ValidationConfig implements ImmutableValue
{
    private const DEFAULT_MAX_KEY_LENGTH = 250;
    private const DEFAULT_MAX_VALUE_SIZE_BYTES = 2097152; // 2MB

    /**
     * @param int $maxKeyLength the maximum allowed length for a cache key
     * @param int $maxValueSize the maximum allowed size in bytes for a serialized value
     * @param array<string> $reservedCharacters characters that are not allowed in cache keys
     * @param string $keyPattern a regex pattern that valid cache keys must match
     */
    private function __construct(
        public readonly int $maxKeyLength,
        public readonly int $maxValueSize,
        public readonly array $reservedCharacters,
        public readonly string $keyPattern
    ) {
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config the raw configuration array
     */
    public static function fromConfig(array $config = []): self
    {
        return new self(
            maxKeyLength: (int) ($config['max_key_length'] ?? self::DEFAULT_MAX_KEY_LENGTH),
            maxValueSize: (int) ($config['max_value_size'] ?? self::DEFAULT_MAX_VALUE_SIZE_BYTES),
            reservedCharacters: (array) ($config['reserved_characters'] ?? ['{}()', '/', '@', ':']),
            keyPattern: (string) ($config['key_pattern'] ?? '/^[a-zA-Z0-9_\-\.]+$/')
        );
    }
}
