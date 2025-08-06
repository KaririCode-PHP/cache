<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Feature Configuration DTO.
 */
final class FeatureConfig implements ImmutableValue
{
    public readonly bool $stampedeProtection;
    public readonly bool $metricsEnabled;
    public readonly bool $eventsEnabled;
    public readonly bool $strictValidation;
    public readonly bool $compressionEnabled;
    public readonly bool $asyncOperations;
    public readonly bool $debugMode;

    public function __construct(array $config = [])
    {
        $this->stampedeProtection = $config['stampede_protection'] ?? true;
        $this->metricsEnabled = $config['metrics_enabled'] ?? true;
        $this->eventsEnabled = $config['events_enabled'] ?? true;
        $this->strictValidation = $config['strict_validation'] ?? true;
        $this->compressionEnabled = $config['compression_enabled'] ?? true;
        $this->asyncOperations = $config['async_operations'] ?? false;
        $this->debugMode = $config['debug_mode'] ?? false;
    }

    public function toArray(): array
    {
        return [
            'stampede_protection' => $this->stampedeProtection,
            'metrics_enabled' => $this->metricsEnabled,
            'events_enabled' => $this->eventsEnabled,
            'strict_validation' => $this->strictValidation,
            'compression_enabled' => $this->compressionEnabled,
            'async_operations' => $this->asyncOperations,
            'debug_mode' => $this->debugMode,
        ];
    }
}
