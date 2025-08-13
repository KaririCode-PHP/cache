<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service\Event;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Contract\Event\PerformanceMonitor;

/**
 * Monitors cache operation performance.
 */
final class CachePerformanceMonitor implements PerformanceMonitor
{
    public function __construct(
        private readonly EventServiceConfig $config
    ) {
    }

    public function checkPerformance(float $duration, string $operation, string $key): ?array
    {
        if (!$this->config->performanceLoggingEnabled) {
            return null;
        }

        $threshold = $this->config->getPerformanceThreshold($operation);
        $durationMs = round($duration * 1000, 3);

        if (!$this->isSlowOperation($duration, $operation)) {
            return null;
        }

        return [
            'operation' => $operation,
            'key' => $key,
            'duration_ms' => $durationMs,
            'threshold_ms' => $threshold,
            'exceeded_by' => $durationMs - $threshold,
        ];
    }

    public function isSlowOperation(float $duration, string $operation): bool
    {
        $threshold = $this->config->getPerformanceThreshold($operation);
        $durationMs = round($duration * 1000, 3);

        return $durationMs > $threshold;
    }
}
