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
 *
 * Key improvements:
 * - Fixed hit rate calculation to always return float
 * - Corrected total operations calculation logic
 * - Fixed detailed metrics memory management
 * - Proper reset functionality for performance data
 * - Enhanced error handling and edge cases
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
class MetricsService
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

    /**
     * Maximum number of duration records to keep per operation for memory efficiency.
     */
    private const MAX_DURATION_RECORDS = 1000;

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
        // Ensure the operation counter exists
        if (!array_key_exists($operation, $this->counters)) {
            $this->counters[$operation] = 0;
            if ($this->detailedMetricsEnabled) {
                $this->durations[$operation] = [];
            }
        }

        // Increment the operation counter
        ++$this->counters[$operation];

        // Record failure if applicable
        if (!$success) {
            ++$this->counters['errors'];
        }

        // Record detailed metrics if enabled
        if ($this->detailedMetricsEnabled) {
            $this->recordDurationWithMemoryManagement($operation, $duration);
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

        // Calculate total operations excluding metadata counters (hits, misses, errors)
        $totalOperations = $this->calculateTotalOperations();

        $metrics = [
            'counters' => $this->counters,
            'hit_rate' => $this->calculateHitRate($totalHits, $totalReads),
            'total_operations' => $totalOperations,
            'uptime_seconds' => microtime(true) - $this->startTime,
        ];

        if ($this->detailedMetricsEnabled && !empty($this->durations)) {
            $metrics['performance'] = $this->calculatePerformanceMetrics();
        }

        return $metrics;
    }

    /**
     * Resets all metrics counters to zero.
     */
    public function reset(): void
    {
        // Reset all counters to zero
        foreach (array_keys($this->counters) as $key) {
            $this->counters[$key] = 0;
        }

        // Clear all duration data
        $this->durations = [];

        // Reset start time
        $this->startTime = microtime(true);

        $this->logger->info('Cache metrics have been reset.');
    }

    /**
     * Records duration with proper memory management.
     *
     * @param string $operation The operation name
     * @param float $duration The duration in seconds
     */
    private function recordDurationWithMemoryManagement(string $operation, float $duration): void
    {
        // Initialize duration array if not exists
        if (!isset($this->durations[$operation])) {
            $this->durations[$operation] = [];
        }

        // Add the new duration
        $this->durations[$operation][] = $duration;

        // Maintain memory limit by removing oldest records
        if (count($this->durations[$operation]) > self::MAX_DURATION_RECORDS) {
            array_shift($this->durations[$operation]);
        }
    }

    /**
     * Calculates hit rate ensuring always returns a float.
     *
     * @param int $totalHits Number of cache hits
     * @param int $totalReads Number of read operations
     *
     * @return float Hit rate as a float between 0.0 and 1.0
     */
    private function calculateHitRate(int $totalHits, int $totalReads): float
    {
        return $totalReads > 0 ? (float) ($totalHits / $totalReads) : 0.0;
    }

    /**
     * Calculates total operations excluding metadata/derived counters.
     *
     * @return int Total number of actual cache operations
     */
    private function calculateTotalOperations(): int
    {
        $metadataCounters = ['hits', 'misses', 'errors'];
        $total = 0;

        foreach ($this->counters as $operation => $count) {
            if (!in_array($operation, $metadataCounters, true)) {
                $total += $count;
            }
        }

        return $total;
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

            // Create a copy for sorting to avoid modifying the original array
            $sortedTimes = $times;
            sort($sortedTimes);

            $durationsCount = count($sortedTimes);
            $totalTime = array_sum($sortedTimes);

            // Use the actual operation counter for the count, not the duration array length
            $actualCount = $this->counters[$operation] ?? $durationsCount;

            $performance[$operation] = [
                'count' => $actualCount, // Total operations, not just stored durations
                'total_duration_ms' => round($totalTime * 1000, 4),
                'avg_ms' => round(($totalTime / $durationsCount) * 1000, 4), // Use durationsCount for average
                'p50_ms' => round($this->calculatePercentile($sortedTimes, 0.50) * 1000, 4),
                'p95_ms' => round($this->calculatePercentile($sortedTimes, 0.95) * 1000, 4),
                'p99_ms' => round($this->calculatePercentile($sortedTimes, 0.99) * 1000, 4),
            ];
        }

        return $performance;
    }

    /**
     * Calculates a percentile value from a sorted array.
     *
     * @param array<float> $sortedValues Sorted array of values
     * @param float $percentile Percentile to calculate (0.0 to 1.0)
     *
     * @return float The percentile value
     */
    private function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);

        if (0 === $count) {
            return 0.0;
        }

        if (1 === $count) {
            return $sortedValues[0];
        }

        // Calculate the index for the percentile
        $index = $percentile * ($count - 1);

        // If the index is an integer, return the exact value
        if ($index === floor($index)) {
            return $sortedValues[(int) $index];
        }

        // Otherwise, interpolate between the two nearest values
        $lowerIndex = (int) floor($index);
        $upperIndex = (int) ceil($index);
        $weight = $index - $lowerIndex;

        return $sortedValues[$lowerIndex] + ($weight * ($sortedValues[$upperIndex] - $sortedValues[$lowerIndex]));
    }
}
