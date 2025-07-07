<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;

/**
 * Performance-focused event listener for detailed timing analysis.
 */
final class PerformanceEventListener implements CacheEventListener
{
    private array $performanceMetrics = [];
    private array $slowOperations = [];
    private float $slowThreshold;

    public function __construct(float $slowThreshold = 0.1) // 100ms threshold
    {
        $this->slowThreshold = $slowThreshold;
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        $this->recordPerformanceMetric('hit', $key, [
            'value_size' => $this->calculateValueSize($value),
            'value_type' => gettype($value),
        ]);
    }

    public function onCacheMiss(string $key): void
    {
        $this->recordPerformanceMetric('miss', $key);
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $startTime = microtime(true);

        $this->recordPerformanceMetric('set', $key, [
            'value_size' => $this->calculateValueSize($value),
            'value_type' => gettype($value),
            'serialization_time' => microtime(true) - $startTime,
        ]);
    }

    public function onCacheDelete(string $key): void
    {
        $this->recordPerformanceMetric('delete', $key);
    }

    public function onCacheClear(): void
    {
        $this->recordPerformanceMetric('clear', 'all');
    }

    public function getPerformanceReport(): array
    {
        return [
            'total_operations' => count($this->performanceMetrics),
            'slow_operations' => $this->slowOperations,
            'average_times' => $this->calculateAverageTimes(),
            'size_distribution' => $this->calculateSizeDistribution(),
            'most_accessed_keys' => $this->getMostAccessedKeys(),
        ];
    }

    public function getSlowOperations(): array
    {
        return $this->slowOperations;
    }

    private function recordPerformanceMetric(string $operation, string $key, array $data = []): void
    {
        $timestamp = microtime(true);

        $metric = [
            'operation' => $operation,
            'key' => $key,
            'timestamp' => $timestamp,
            'data' => $data,
        ];

        $this->performanceMetrics[] = $metric;

        // Track slow operations
        $duration = $data['duration'] ?? 0;
        if ($duration > $this->slowThreshold) {
            $this->slowOperations[] = array_merge($metric, ['duration' => $duration]);
        }
    }

    private function calculateValueSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    private function calculateAverageTimes(): array
    {
        $times = [];
        foreach ($this->performanceMetrics as $metric) {
            $operation = $metric['operation'];
            $duration = $metric['data']['duration'] ?? 0;

            if (!isset($times[$operation])) {
                $times[$operation] = [];
            }
            $times[$operation][] = $duration;
        }

        $averages = [];
        foreach ($times as $operation => $durations) {
            $averages[$operation] = array_sum($durations) / count($durations);
        }

        return $averages;
    }

    private function calculateSizeDistribution(): array
    {
        $sizes = [];
        foreach ($this->performanceMetrics as $metric) {
            if (isset($metric['data']['value_size'])) {
                $size = $metric['data']['value_size'];
                $range = $this->getSizeRange($size);
                $sizes[$range] = ($sizes[$range] ?? 0) + 1;
            }
        }

        return $sizes;
    }

    private function getSizeRange(int $size): string
    {
        if ($size < 1024) {
            return 'small (<1KB)';
        }
        if ($size < 10240) {
            return 'medium (1-10KB)';
        }
        if ($size < 102400) {
            return 'large (10-100KB)';
        }

        return 'xlarge (>100KB)';
    }

    private function getMostAccessedKeys(int $limit = 10): array
    {
        $keyAccess = [];
        foreach ($this->performanceMetrics as $metric) {
            $key = $metric['key'];
            $keyAccess[$key] = ($keyAccess[$key] ?? 0) + 1;
        }

        arsort($keyAccess);

        return array_slice($keyAccess, 0, $limit, true);
    }
}
