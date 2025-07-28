<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * DTO for cache features configuration with validation and defaults.
 */
final class FeatureConfig implements ImmutableValue
{
    public function __construct(
        public readonly bool $stampedeProtection = true,
        public readonly bool $metricsEnabled = true,
        public readonly bool $slidingExpiration = false,
        public readonly bool $eventsEnabled = true,
        public readonly bool $psr14EventsEnabled = false,
        public readonly bool $strictValidation = true
    ) {
    }

    /**
     * Create from configuration array with validation.
     */
    public static function fromArray(array $features): self
    {
        return new self(
            stampedeProtection: (bool) ($features['stampede_protection'] ?? true),
            metricsEnabled: (bool) ($features['metrics_enabled'] ?? true),
            slidingExpiration: (bool) ($features['sliding_expiration'] ?? false),
            eventsEnabled: (bool) ($features['events_enabled'] ?? true),
            psr14EventsEnabled: (bool) ($features['psr14_events_enabled'] ?? false),
            strictValidation: (bool) ($features['strict_validation'] ?? true)
        );
    }

    /**
     * Convert to array for compatibility.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'stampede_protection' => $this->stampedeProtection,
            'metrics_enabled' => $this->metricsEnabled,
            'sliding_expiration' => $this->slidingExpiration,
            'events_enabled' => $this->eventsEnabled,
            'psr14_events_enabled' => $this->psr14EventsEnabled,
            'strict_validation' => $this->strictValidation,
        ];
    }

    /**
     * Check if any monitoring features are enabled.
     */
    public function hasMonitoringEnabled(): bool
    {
        return $this->metricsEnabled || $this->eventsEnabled || $this->psr14EventsEnabled;
    }

    /**
     * Check if any validation features are enabled.
     */
    public function hasValidationEnabled(): bool
    {
        return $this->strictValidation;
    }

    /**
     * Get performance-related features.
     *
     * @return array<string, bool>
     */
    public function getPerformanceFeatures(): array
    {
        return [
            'stampede_protection' => $this->stampedeProtection,
            'sliding_expiration' => $this->slidingExpiration,
        ];
    }
}
