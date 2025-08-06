<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Logging Configuration DTO.
 */
final class LoggingConfig implements ImmutableValue
{
    public readonly bool $enabled;
    public readonly string $channel;
    public readonly string $level;
    public readonly bool $includeValues;
    public readonly int $maxValueLength;

    public function __construct(array $config = [])
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->channel = $config['channel'] ?? 'cache';
        $this->level = $config['level'] ?? 'info';
        $this->includeValues = $config['include_values'] ?? false;
        $this->maxValueLength = $config['max_value_length'] ?? 100;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'channel' => $this->channel,
            'level' => $this->level,
            'include_values' => $this->includeValues,
            'max_value_length' => $this->maxValueLength,
        ];
    }
}
