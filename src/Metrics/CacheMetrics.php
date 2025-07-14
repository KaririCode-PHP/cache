<?php

declare(strict_types=1);

namespace KaririCode\Cache\Metrics;

use KaririCode\Contract\ImmutableValue;

/**
 * CacheMetrics - New immutable metrics data class.
 */
final class CacheMetrics implements ImmutableValue
{
    public function __construct(
        private readonly array $metrics
    ) {
    }

    public function getHits(): int
    {
        return $this->metrics['hits'] ?? 0;
    }

    public function getMisses(): int
    {
        return $this->metrics['misses'] ?? 0;
    }

    public function getHitRate(): float
    {
        return $this->metrics['hit_rate'] ?? 0.0;
    }

    public function getTotalOperations(): int
    {
        return $this->metrics['total_operations'] ?? 0;
    }

    public function getAverageHitTime(): float
    {
        return $this->metrics['average_hit_time'] ?? 0.0;
    }

    public function getAverageGenerationTime(): float
    {
        return $this->metrics['average_generation_time'] ?? 0.0;
    }

    public function getEvictions(): int
    {
        return $this->metrics['evictions'] ?? 0;
    }

    public function getErrors(): int
    {
        return $this->metrics['errors'] ?? 0;
    }

    public function getUptime(): float
    {
        return $this->metrics['uptime'] ?? 0.0;
    }

    public function getLastReset(): int
    {
        return $this->metrics['last_reset'] ?? 0;
    }

    public function toArray(): array
    {
        return $this->metrics;
    }
}
