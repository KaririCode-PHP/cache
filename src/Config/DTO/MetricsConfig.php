<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that holds configuration for collecting cache metrics.
 *
 * It defines whether metrics are enabled, the collector to use, and how
 * and when metrics should be exported.
 */
final class MetricsConfig implements ImmutableValue
{
    /**
     * @param bool $enabled toggles metrics collection on or off
     * @param string $collector the name of the metrics collector implementation to use
     * @param string $exportFormat The format for exporting metrics (e.g., 'json', 'prometheus').
     * @param int $exportInterval the interval in seconds for automatic metric exporting
     * @param bool $includeDriverStats whether to include driver-specific stats in the metrics
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $collector,
        public readonly string $exportFormat,
        public readonly int $exportInterval,
        public readonly bool $includeDriverStats
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
            collector: (string) ($config['collector'] ?? 'default'),
            exportFormat: (string) ($config['export_format'] ?? 'json'),
            exportInterval: (int) ($config['export_interval'] ?? 60),
            includeDriverStats: (bool) ($config['include_driver_stats'] ?? true)
        );
    }
}
