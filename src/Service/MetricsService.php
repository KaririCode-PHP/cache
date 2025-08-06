<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for collecting and calculating cache performance metrics.
 *
 * This class provides a centralized and encapsulated way to track various
 * cache operations, calculate hit rates, average response times, and more.
 */
final class MetricsService
{
    /** @var array<string, int> Counters for different operation types. */
    private array $counters = [
        'get' => 0,
        'set' => 0,
        'delete' => 0,
        'clear' => 0,
        'has' => 0,
        'getMultiple' => 0,
        'setMultiple' => 0,
        'deleteMultiple' => 0,
        'increment' => 0,
        'decrement' => 0,
        'add' => 0,
        'hits' => 0,
        'misses' => 0,
        'errors' => 0,
    ];

    /** @var array<string, list<float>> Stores durations for each operation type to calculate percentiles. */
    private array $durations = [];

    private float $startTime;
    private bool $detailedMetricsEnabled = false;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->startTime = microtime(true);
    }

    /**
     * Enables the collection of detailed performance metrics like latency percentiles.
     */
    public function enableDetailedMetrics(): void
    {
        $this->detailedMetricsEnabled = true;
    }

    /**
     * Records a single cache operation. This is the primary entry point for metrics.
     *
     * @param string $operation The name of the operation (e.g., 'get', 'set', 'hit', 'miss').
     * @param float $duration the duration of the operation in seconds
     * @param bool $success whether the operation was successful
     */
    public function record(string $operation, float $duration, bool $success): void
    {
        if (!array_key_exists($operation, $this->counters)) {
            $this->counters[$operation] = 0;
            if ($this->detailedMetricsEnabled) {
                $this->durations[$operation] = [];
            }
        }

        ++$this->counters[$operation];

        if (!$success) {
            ++$this->counters['errors'];
        }

        if ($this->detailedMetricsEnabled) {
            $this->durations[$operation][] = $duration;

            // To save memory, keep only the last 1000 duration records per operation
            if (count($this->durations[$operation]) > 1000) {
                array_shift($this->durations[$operation]);
            }
        }
    }

    /**
     * Retrieves a snapshot of the current cache metrics.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $totalReads = ($this->counters['get'] ?? 0) + ($this->counters['getMultiple'] ?? 0);
        $totalHits = $this->counters['hits'] ?? 0;
        $totalOperations = array_sum($this->counters) - $this->counters['hits'] - $this->counters['misses'];

        $metrics = [
            'counters' => $this->counters,
            'hit_rate' => $totalReads > 0 ? ($totalHits / $totalReads) : 0.0,
            'total_operations' => $totalOperations,
            'uptime_seconds' => microtime(true) - $this->startTime,
        ];

        if ($this->detailedMetricsEnabled) {
            $metrics['performance'] = $this->calculatePerformanceMetrics();
        }

        return $metrics;
    }

    /**
     * Resets all metrics counters to zero.
     */
    public function reset(): void
    {
        foreach (array_keys($this->counters) as $key) {
            $this->counters[$key] = 0;
        }
        $this->durations = [];
        $this->startTime = microtime(true);
        $this->logger->info('Cache metrics have been reset.');
    }

    /**
     * Calculates performance metrics like average, median (p50), and p95/p99 latencies.
     *
     * @return array<string, mixed>
     */
    private function calculatePerformanceMetrics(): array
    {
        $performance = [];
        foreach ($this->durations as $operation => $times) {
            if (empty($times)) {
                continue;
            }

            sort($times);
            $count = count($times);
            $totalTime = array_sum($times);

            $performance[$operation] = [
                'count' => $count,
                'total_duration_ms' => $totalTime * 1000,
                'avg_ms' => ($totalTime / $count) * 1000,
                'p50_ms' => $times[(int) ($count * 0.50)] * 1000, // Median
                'p95_ms' => $times[(int) ($count * 0.95)] * 1000,
                'p99_ms' => $times[(int) ($count * 0.99)] * 1000,
            ];
        }

        return $performance;
    }
}
