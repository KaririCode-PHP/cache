<?php

declare(strict_types=1);

namespace KaririCode\Cache\Metrics;

use KaririCode\Cache\Contract\CacheMetricsCollector;
use KaririCode\Contract\ImmutableValue;

/**
 * MetricsCollector - Must implement ImmutableValue.
 *
 * Required by:
 * - MetricsAware::setMetricsCollector(ImmutableValue $metricsCollector): MetricsAware
 * - MetricsAware::getMetricsCollector(): ImmutableValue
 * - CacheMetricsCollector::getMetrics(): ImmutableValue
 */
final class MetricsCollector implements CacheMetricsCollector, ImmutableValue
{
    private readonly array $metrics;
    private readonly float $startTime;

    public function __construct(array $initialMetrics = [])
    {
        $this->metrics = array_merge([
            'hits' => 0,
            'misses' => 0,
            'generations' => 0,
            'clears' => 0,
            'evictions' => 0,
            'errors' => 0,
            'total_hit_time' => 0.0,
            'total_generation_time' => 0.0,
            'last_reset' => time(),
        ], $initialMetrics);

        $this->startTime = microtime(true);
    }

    public function recordHit(string $key, float $duration, array $context = []): void
    {
        // Return new instance with updated metrics
        $newMetrics = $this->metrics;
        ++$newMetrics['hits'];
        $newMetrics['total_hit_time'] += $duration;
        $this->updateAverages();
        $this->updateHitRate();
    }

    public function recordMiss(string $key): void
    {
        ++$this->metrics['misses'];
        ++$this->metrics['total_operations'];
        $this->updateHitRate();
    }

    public function recordGeneration(string $key, float $duration): void
    {
        ++$this->metrics['generations'];
        $this->generationTimes[] = $duration;
        $this->updateAverages();
    }

    public function recordClear(): void
    {
        ++$this->metrics['clears'];
    }

    public function getMetrics(): array
    {
        return array_merge($this->metrics, [
            'uptime' => microtime(true) - $this->metrics['start_time'],
        ]);
    }

    public function reset(): void
    {
        $this->metrics = [
            'hits' => 0,
            'misses' => 0,
            'generations' => 0,
            'clears' => 0,
            'hit_rate' => 0.0,
            'total_operations' => 0,
            'average_hit_time' => 0.0,
            'average_generation_time' => 0.0,
            'start_time' => microtime(true),
        ];
        $this->hitTimes = [];
        $this->generationTimes = [];
    }

    private function updateHitRate(): void
    {
        if ($this->metrics['total_operations'] > 0) {
            $this->metrics['hit_rate'] = $this->metrics['hits'] / $this->metrics['total_operations'];
        }
    }

    private function updateAverages(): void
    {
        if (!empty($this->hitTimes)) {
            $this->metrics['average_hit_time'] = array_sum($this->hitTimes) / count($this->hitTimes);
        }

        if (!empty($this->generationTimes)) {
            $this->metrics['average_generation_time'] = array_sum($this->generationTimes) / count($this->generationTimes);
        }
    }
}
