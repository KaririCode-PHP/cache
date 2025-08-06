<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Validation Configuration DTO.
 */
final class ValidationConfig implements ImmutableValue
{
    public readonly int $maxKeyLength;
    public readonly int $maxValueSize;
    public readonly array $reservedCharacters;
    public readonly string $keyPattern;

    public function __construct(array $config = [])
    {
        $this->maxKeyLength = $config['max_key_length'] ?? 250;
        $this->maxValueSize = $config['max_value_size'] ?? 2097152; // 2MB
        $this->reservedCharacters = $config['reserved_characters'] ?? ['{}()', '/', '@', ':'];
        $this->keyPattern = $config['key_pattern'] ?? '/^[a-zA-Z0-9_\-\.]+$/';
    }

    public function toArray(): array
    {
        return [
            'max_key_length' => $this->maxKeyLength,
            'max_value_size' => $this->maxValueSize,
            'reserved_characters' => $this->reservedCharacters,
            'key_pattern' => $this->keyPattern,
        ];
    }
}
