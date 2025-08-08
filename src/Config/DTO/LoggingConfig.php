<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that holds configuration for logging cache operations.
 *
 * It defines whether logging is enabled, the target channel and level,
 * and rules for including potentially sensitive cached values in logs.
 */
final class LoggingConfig implements ImmutableValue
{
    /**
     * @param bool $enabled toggles logging for cache operations on or off
     * @param string $channel The logging channel to use (e.g., 'cache', 'stderr').
     * @param string $level The minimum log level to record (e.g., 'info', 'debug').
     * @param bool $includeValues whether to include cached values in log messages
     * @param int $maxValueLength the maximum length of a value to be included in logs
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $channel,
        public readonly string $level,
        public readonly bool $includeValues,
        public readonly int $maxValueLength
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
            enabled: (bool) ($config['enabled'] ?? true),
            channel: (string) ($config['channel'] ?? 'cache'),
            level: (string) ($config['level'] ?? 'info'),
            includeValues: (bool) ($config['include_values'] ?? false),
            maxValueLength: (int) ($config['max_value_length'] ?? 100)
        );
    }
}
