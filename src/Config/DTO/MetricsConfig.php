<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Metrics Configuration DTO.
 */
final class MetricsConfig implements ImmutableValue
{
    public readonly bool $enabled;
    public readonly string $collector;
    public readonly string $exportFormat;
    public readonly int $exportInterval;
    public readonly bool $includeDriverStats;

    public function __construct(array $config = [])
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->collector = $config['collector'] ?? 'default';
        $this->exportFormat = $config['export_format'] ?? 'json';
        $this->exportInterval = $config['export_interval'] ?? 60;
        $this->includeDriverStats = $config['include_driver_stats'] ?? true;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'collector' => $this->collector,
            'export_format' => $this->exportFormat,
            'export_interval' => $this->exportInterval,
            'include_driver_stats' => $this->includeDriverStats,
        ];
    }
}
