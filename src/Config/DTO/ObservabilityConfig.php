<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * DTO para as configurações de observabilidade e monitoramento do cache.
 */
final class ObservabilityConfig implements ImmutableValue
{
    public function __construct(
        public readonly bool $performanceTracking = true,
        public readonly bool $securityMonitoring = false,
        public readonly int $maxEvents = 1000,
        public readonly float $slowThreshold = 0.1,
        public readonly bool $debugLogging = false,
        public readonly int $metricsInterval = 60
    ) {
    }

    /**
     * Cria uma instância a partir de um array de configuração.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $maxEvents = $config['max_events'] ?? 1000;
        $slowThreshold = $config['slow_threshold'] ?? 0.1;
        $metricsInterval = $config['metrics_collection_interval'] ?? 60;

        return new self(
            performanceTracking: (bool) ($config['enable_performance_tracking'] ?? true),
            securityMonitoring: (bool) ($config['enable_security_monitoring'] ?? false),
            maxEvents: is_numeric($maxEvents) ? (int) $maxEvents : 1000,
            slowThreshold: is_numeric($slowThreshold) ? (float) $slowThreshold : 0.1,
            debugLogging: (bool) ($config['enable_debug_logging'] ?? false),
            metricsInterval: is_numeric($metricsInterval) ? (int) $metricsInterval : 60
        );
    }

    /**
     * Converte o DTO de volta para um array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enable_performance_tracking' => $this->performanceTracking,
            'enable_security_monitoring' => $this->securityMonitoring,
            'max_events' => $this->maxEvents,
            'slow_threshold' => $this->slowThreshold,
            'enable_debug_logging' => $this->debugLogging,
            'metrics_collection_interval' => $this->metricsInterval,
        ];
    }
}
