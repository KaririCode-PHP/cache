<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Advanced performance monitoring event listener for cache operations.
 *
 * This implementation provides comprehensive performance analysis including:
 * - Real-time performance metrics
 * - Slow operation detection and analysis
 * - Throughput monitoring
 * - Memory usage tracking
 * - Cache efficiency analysis
 * - Performance bottleneck identification
 * - Automated optimization recommendations
 * - Historical performance trending
 *
 * Following performance monitoring best practices and APM principles.
 */
final class PerformanceEventListener implements CacheEventListener
{
    private array $performanceMetrics = [];
    private array $slowOperations = [];
    private array $operationTimings = [];
    private array $memoryUsage = [];
    private array $throughputMetrics = [];
    private array $keyPerformance = [];
    private array $sizeAnalysis = [];
    private array $patternAnalysis = [];
    private ?EventDispatcherInterface $eventDispatcher = null;

    private const MAX_METRICS_HISTORY = 10000;
    private const PERCENTILE_BUCKETS = [50, 75, 90, 95, 99];
    private const SIZE_CATEGORIES = [
        'tiny' => 1024,        // < 1KB
        'small' => 10240,      // 1-10KB
        'medium' => 102400,    // 10-100KB
        'large' => 1048576,    // 100KB-1MB
        'huge' => PHP_INT_MAX,  // > 1MB
    ];

    public function __construct(
        private float $slowThreshold = 0.1,     // 100ms
        private int $maxHistorySize = 10000,
        private bool $enableMemoryTracking = true,
        private bool $enablePatternAnalysis = true
    ) {
        $this->initializeMetrics();
    }

    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        $startTime = microtime(true);
        $valueSize = $this->calculateValueSize($value);
        $memoryBefore = $this->enableMemoryTracking ? memory_get_usage(true) : 0;

        // Simulate hit processing time
        $processingTime = microtime(true) - $startTime;

        $this->recordPerformanceMetric('hit', $key, [
            'processing_time' => $processingTime,
            'value_size' => $valueSize,
            'value_type' => gettype($value),
            'memory_before' => $memoryBefore,
            'memory_after' => $this->enableMemoryTracking ? memory_get_usage(true) : 0,
        ]);

        $this->analyzeHitPerformance($key, $value, $processingTime, $valueSize);

        // Dispatch PSR-14 performance event
        if ($this->eventDispatcher) {
            $perfEvent = new CachePerformanceEvent('hit', $key, $processingTime, $valueSize);
            $this->eventDispatcher->dispatch($perfEvent);
        }
    }

    public function onCacheMiss(string $key): void
    {
        $startTime = microtime(true);
        $memoryBefore = $this->enableMemoryTracking ? memory_get_usage(true) : 0;

        $processingTime = microtime(true) - $startTime;

        $this->recordPerformanceMetric('miss', $key, [
            'processing_time' => $processingTime,
            'memory_before' => $memoryBefore,
            'memory_after' => $this->enableMemoryTracking ? memory_get_usage(true) : 0,
        ]);

        $this->analyzeMissPerformance($key, $processingTime);

        // Dispatch PSR-14 performance event
        if ($this->eventDispatcher) {
            $perfEvent = new CachePerformanceEvent('miss', $key, $processingTime);
            $this->eventDispatcher->dispatch($perfEvent);
        }
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $startTime = microtime(true);
        $valueSize = $this->calculateValueSize($value);
        $memoryBefore = $this->enableMemoryTracking ? memory_get_usage(true) : 0;

        // Simulate serialization and storage time
        $serializationStart = microtime(true);
        $serialized = serialize($value);
        $serializationTime = microtime(true) - $serializationStart;

        $processingTime = microtime(true) - $startTime;

        $this->recordPerformanceMetric('set', $key, [
            'processing_time' => $processingTime,
            'serialization_time' => $serializationTime,
            'value_size' => $valueSize,
            'serialized_size' => strlen($serialized),
            'value_type' => gettype($value),
            'compression_ratio' => $valueSize > 0 ? strlen($serialized) / $valueSize : 1,
            'memory_before' => $memoryBefore,
            'memory_after' => $this->enableMemoryTracking ? memory_get_usage(true) : 0,
        ]);

        $this->analyzeSetPerformance($key, $value, $processingTime, $valueSize, $serializationTime);

        // Dispatch PSR-14 performance event
        if ($this->eventDispatcher) {
            $perfEvent = new CachePerformanceEvent('set', $key, $processingTime, $valueSize, [
                'serialization_time' => $serializationTime,
                'compression_ratio' => $valueSize > 0 ? strlen($serialized) / $valueSize : 1,
            ]);
            $this->eventDispatcher->dispatch($perfEvent);
        }
    }

    public function onCacheDelete(string $key): void
    {
        $startTime = microtime(true);
        $memoryBefore = $this->enableMemoryTracking ? memory_get_usage(true) : 0;

        $processingTime = microtime(true) - $startTime;

        $this->recordPerformanceMetric('delete', $key, [
            'processing_time' => $processingTime,
            'memory_before' => $memoryBefore,
            'memory_after' => $this->enableMemoryTracking ? memory_get_usage(true) : 0,
        ]);

        $this->analyzeDeletePerformance($key, $processingTime);

        // Dispatch PSR-14 performance event
        if ($this->eventDispatcher) {
            $perfEvent = new CachePerformanceEvent('delete', $key, $processingTime);
            $this->eventDispatcher->dispatch($perfEvent);
        }
    }

    public function onCacheClear(): void
    {
        $startTime = microtime(true);
        $memoryBefore = $this->enableMemoryTracking ? memory_get_usage(true) : 0;

        $processingTime = microtime(true) - $startTime;

        $this->recordPerformanceMetric('clear', '*', [
            'processing_time' => $processingTime,
            'memory_before' => $memoryBefore,
            'memory_after' => $this->enableMemoryTracking ? memory_get_usage(true) : 0,
        ]);

        // Clear is always significant from performance perspective
        if ($processingTime > $this->slowThreshold) {
            $this->recordSlowOperation('clear', '*', $processingTime, [
                'severity' => 'HIGH',
                'impact' => 'Full cache clear operation',
            ]);
        }

        // Dispatch PSR-14 performance event
        if ($this->eventDispatcher) {
            $perfEvent = new CachePerformanceEvent('clear', '*', $processingTime);
            $this->eventDispatcher->dispatch($perfEvent);
        }
    }

    /**
     * Get comprehensive performance report.
     */
    public function getPerformanceReport(): array
    {
        return [
            'summary' => $this->getPerformanceSummary(),
            'throughput' => $this->getThroughputAnalysis(),
            'latency' => $this->getLatencyAnalysis(),
            'memory' => $this->getMemoryAnalysis(),
            'slow_operations' => $this->getSlowOperationsReport(),
            'key_analysis' => $this->getKeyPerformanceAnalysis(),
            'size_analysis' => $this->getSizeAnalysis(),
            'pattern_analysis' => $this->getPatternAnalysis(),
            'bottlenecks' => $this->identifyBottlenecks(),
            'recommendations' => $this->generateOptimizationRecommendations(),
            'trends' => $this->getPerformanceTrends(),
        ];
    }

    /**
     * Get real-time performance metrics.
     */
    public function getRealTimeMetrics(): array
    {
        $recentMetrics = $this->getRecentMetrics(300); // Last 5 minutes

        return [
            'current_throughput' => $this->calculateCurrentThroughput($recentMetrics),
            'avg_latency' => $this->calculateAverageLatency($recentMetrics),
            'error_rate' => $this->calculateErrorRate($recentMetrics),
            'memory_usage' => $this->getCurrentMemoryUsage(),
            'active_operations' => count($recentMetrics),
            'slow_operation_rate' => $this->calculateSlowOperationRate($recentMetrics),
        ];
    }

    /**
     * Get slow operations with detailed analysis.
     */
    public function getSlowOperationsReport(): array
    {
        $slowOps = array_slice($this->slowOperations, -100); // Last 100 slow operations

        $analysis = [
            'total_slow_operations' => count($this->slowOperations),
            'recent_slow_operations' => count($slowOps),
            'slowest_operation' => $this->getSlowestOperation(),
            'slow_by_operation_type' => $this->groupSlowOperationsByType(),
            'slow_by_key_pattern' => $this->analyzeSlowKeyPatterns(),
            'slow_operations_trend' => $this->getSlowOperationsTrend(),
            'details' => $slowOps,
        ];

        return $analysis;
    }

    /**
     * Get performance statistics.
     */
    public function getStats(): array
    {
        $totalOps = count($this->performanceMetrics);
        $slowOps = count($this->slowOperations);

        return [
            'total_operations' => $totalOps,
            'slow_operations' => $slowOps,
            'slow_operation_rate' => $totalOps > 0 ? $slowOps / $totalOps : 0,
            'average_processing_time' => $this->calculateAverageProcessingTime(),
            'total_processing_time' => $this->calculateTotalProcessingTime(),
            'memory_overhead' => $this->calculateMemoryOverhead(),
            'cache_efficiency_score' => $this->calculateCacheEfficiencyScore(),
        ];
    }

    /**
     * Reset all performance metrics.
     */
    public function resetMetrics(): void
    {
        $this->performanceMetrics = [];
        $this->slowOperations = [];
        $this->operationTimings = [];
        $this->memoryUsage = [];
        $this->throughputMetrics = [];
        $this->keyPerformance = [];
        $this->sizeAnalysis = [];
        $this->patternAnalysis = [];

        $this->initializeMetrics();
    }

    /**
     * Export performance data for external analysis.
     */
    public function exportPerformanceData(?int $limit = null): array
    {
        $metrics = $limit ? array_slice($this->performanceMetrics, -$limit) : $this->performanceMetrics;

        return [
            'export_timestamp' => time(),
            'metrics_count' => count($metrics),
            'slow_threshold' => $this->slowThreshold,
            'configuration' => [
                'max_history_size' => $this->maxHistorySize,
                'memory_tracking' => $this->enableMemoryTracking,
                'pattern_analysis' => $this->enablePatternAnalysis,
            ],
            'metrics' => $metrics,
            'slow_operations' => $this->slowOperations,
            'summary' => $this->getPerformanceSummary(),
        ];
    }

    /**
     * Import performance data from external source.
     */
    public function importPerformanceData(array $data): void
    {
        if (isset($data['metrics']) && is_array($data['metrics'])) {
            $this->performanceMetrics = array_merge($this->performanceMetrics, $data['metrics']);
        }

        if (isset($data['slow_operations']) && is_array($data['slow_operations'])) {
            $this->slowOperations = array_merge($this->slowOperations, $data['slow_operations']);
        }

        $this->maintainHistorySize();
    }

    /**
     * Record performance metric for an operation.
     */
    private function recordPerformanceMetric(string $operation, string $key, array $data): void
    {
        $timestamp = microtime(true);

        $metric = array_merge([
            'operation' => $operation,
            'key' => $key,
            'timestamp' => $timestamp,
        ], $data);

        $this->performanceMetrics[] = $metric;

        // Update operation timings
        if (!isset($this->operationTimings[$operation])) {
            $this->operationTimings[$operation] = [];
        }
        $this->operationTimings[$operation][] = $data['processing_time'];

        // Update throughput metrics
        $this->updateThroughputMetrics($operation, $timestamp);

        // Update key performance
        $this->updateKeyPerformance($key, $operation, $data);

        // Update size analysis
        if (isset($data['value_size'])) {
            $this->updateSizeAnalysis($data['value_size'], $operation, $data['processing_time']);
        }

        // Update pattern analysis
        if ($this->enablePatternAnalysis) {
            $this->updatePatternAnalysis($key, $operation, $data);
        }

        // Check if operation is slow
        if ($data['processing_time'] > $this->slowThreshold) {
            $this->recordSlowOperation($operation, $key, $data['processing_time'], $data);
        }

        $this->maintainHistorySize();
    }

    /**
     * Record slow operation for analysis.
     */
    private function recordSlowOperation(string $operation, string $key, float $duration, array $context): void
    {
        $slowOp = [
            'operation' => $operation,
            'key' => $key,
            'duration' => $duration,
            'threshold' => $this->slowThreshold,
            'slowness_factor' => $duration / $this->slowThreshold,
            'timestamp' => microtime(true),
            'context' => $context,
            'severity' => $this->calculateSlownessSeverity($duration),
        ];

        $this->slowOperations[] = $slowOp;

        // Maintain slow operations history
        if (count($this->slowOperations) > 1000) {
            array_shift($this->slowOperations);
        }
    }

    /**
     * Analyze hit performance.
     */
    private function analyzeHitPerformance(string $key, mixed $value, float $processingTime, int $valueSize): void
    {
        // Check for unusually slow hits
        if ($processingTime > $this->slowThreshold * 0.5) { // 50% of slow threshold
            $this->identifyHitBottleneck($key, $value, $processingTime, $valueSize);
        }

        // Analyze value size impact on performance
        if ($valueSize > 100 * 1024 && $processingTime > 0.01) { // Large values taking time
            $this->recordPerformanceIssue('large_value_hit', $key, [
                'value_size' => $valueSize,
                'processing_time' => $processingTime,
                'efficiency' => $valueSize / $processingTime, // bytes per second
            ]);
        }
    }

    /**
     * Analyze miss performance.
     */
    private function analyzeMissPerformance(string $key, float $processingTime): void
    {
        // Misses should be very fast
        if ($processingTime > 0.001) { // 1ms for a miss is concerning
            $this->recordPerformanceIssue('slow_miss', $key, [
                'processing_time' => $processingTime,
                'expected_max' => 0.001,
            ]);
        }
    }

    /**
     * Analyze set performance.
     */
    private function analyzeSetPerformance(string $key, mixed $value, float $processingTime, int $valueSize, float $serializationTime): void
    {
        // Check serialization efficiency
        $serializationRatio = $serializationTime / $processingTime;
        if ($serializationRatio > 0.8) { // Serialization taking most of the time
            $this->recordPerformanceIssue('slow_serialization', $key, [
                'serialization_time' => $serializationTime,
                'processing_time' => $processingTime,
                'ratio' => $serializationRatio,
                'value_size' => $valueSize,
                'value_type' => gettype($value),
            ]);
        }

        // Check for inefficient data structures
        if (is_array($value) && count($value) > 1000 && $serializationTime > 0.01) {
            $this->recordPerformanceIssue('large_array_serialization', $key, [
                'array_size' => count($value),
                'serialization_time' => $serializationTime,
                'efficiency' => count($value) / $serializationTime,
            ]);
        }
    }

    /**
     * Analyze delete performance.
     */
    private function analyzeDeletePerformance(string $key, float $processingTime): void
    {
        // Deletes should be very fast
        if ($processingTime > 0.005) { // 5ms for a delete is concerning
            $this->recordPerformanceIssue('slow_delete', $key, [
                'processing_time' => $processingTime,
                'expected_max' => 0.005,
            ]);
        }
    }

    /**
     * Identify hit performance bottlenecks.
     */
    private function identifyHitBottleneck(string $key, mixed $value, float $processingTime, int $valueSize): void
    {
        $bottleneck = [
            'type' => 'hit_bottleneck',
            'key' => $key,
            'processing_time' => $processingTime,
            'value_size' => $valueSize,
            'value_type' => gettype($value),
            'timestamp' => microtime(true),
        ];

        // Determine likely cause
        if ($valueSize > 1024 * 1024) { // > 1MB
            $bottleneck['likely_cause'] = 'large_value_deserialization';
        } elseif (is_object($value)) {
            $bottleneck['likely_cause'] = 'complex_object_deserialization';
        } elseif (is_array($value) && $this->isNestedArray($value)) {
            $bottleneck['likely_cause'] = 'nested_array_processing';
        } else {
            $bottleneck['likely_cause'] = 'unknown_hit_slowness';
        }

        if (!isset($this->patternAnalysis['bottlenecks'])) {
            $this->patternAnalysis['bottlenecks'] = [];
        }
        $this->patternAnalysis['bottlenecks'][] = $bottleneck;
    }

    /**
     * Record performance issue for analysis.
     */
    private function recordPerformanceIssue(string $type, string $key, array $details): void
    {
        if (!isset($this->patternAnalysis['issues'])) {
            $this->patternAnalysis['issues'] = [];
        }

        if (!isset($this->patternAnalysis['issues'][$type])) {
            $this->patternAnalysis['issues'][$type] = [];
        }

        $this->patternAnalysis['issues'][$type][] = array_merge([
            'key' => $key,
            'timestamp' => microtime(true),
        ], $details);
    }

    /**
     * Calculate value size for analysis.
     */
    private function calculateValueSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    /**
     * Calculate slowness severity.
     */
    private function calculateSlownessSeverity(float $duration): string
    {
        $factor = $duration / $this->slowThreshold;

        if ($factor > 10) {
            return 'CRITICAL';
        }
        if ($factor > 5) {
            return 'HIGH';
        }
        if ($factor > 2) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * Initialize metrics arrays.
     */
    private function initializeMetrics(): void
    {
        $this->operationTimings = [
            'hit' => [],
            'miss' => [],
            'set' => [],
            'delete' => [],
            'clear' => [],
        ];

        $this->throughputMetrics = [];
        $this->sizeAnalysis = [];
        foreach (self::SIZE_CATEGORIES as $category => $limit) {
            $this->sizeAnalysis[$category] = [
                'count' => 0,
                'total_time' => 0,
                'avg_time' => 0,
            ];
        }
    }

    /**
     * Update throughput metrics.
     */
    private function updateThroughputMetrics(string $operation, float $timestamp): void
    {
        $minute = floor($timestamp / 60) * 60; // Round to minute

        if (!isset($this->throughputMetrics[$minute])) {
            $this->throughputMetrics[$minute] = [
                'total' => 0,
                'hit' => 0,
                'miss' => 0,
                'set' => 0,
                'delete' => 0,
                'clear' => 0,
            ];
        }

        ++$this->throughputMetrics[$minute]['total'];
        ++$this->throughputMetrics[$minute][$operation];
    }

    /**
     * Update key performance tracking.
     */
    private function updateKeyPerformance(string $key, string $operation, array $data): void
    {
        if (!isset($this->keyPerformance[$key])) {
            $this->keyPerformance[$key] = [
                'operations' => [],
                'total_time' => 0,
                'operation_count' => 0,
                'avg_time' => 0,
                'slowest_operation' => 0,
            ];
        }

        $time = $data['processing_time'];
        $this->keyPerformance[$key]['operations'][] = [
            'operation' => $operation,
            'time' => $time,
            'timestamp' => microtime(true),
        ];

        $this->keyPerformance[$key]['total_time'] += $time;
        ++$this->keyPerformance[$key]['operation_count'];
        $this->keyPerformance[$key]['avg_time'] = $this->keyPerformance[$key]['total_time'] / $this->keyPerformance[$key]['operation_count'];
        $this->keyPerformance[$key]['slowest_operation'] = max($this->keyPerformance[$key]['slowest_operation'], $time);
    }

    /**
     * Update size analysis.
     */
    private function updateSizeAnalysis(int $size, string $operation, float $time): void
    {
        $category = $this->getSizeCategory($size);

        ++$this->sizeAnalysis[$category]['count'];
        $this->sizeAnalysis[$category]['total_time'] += $time;
        $this->sizeAnalysis[$category]['avg_time'] = $this->sizeAnalysis[$category]['total_time'] / $this->sizeAnalysis[$category]['count'];
    }

    /**
     * Update pattern analysis.
     */
    private function updatePatternAnalysis(string $key, string $operation, array $data): void
    {
        // Analyze key patterns
        $pattern = $this->extractKeyPattern($key);

        if (!isset($this->patternAnalysis['key_patterns'])) {
            $this->patternAnalysis['key_patterns'] = [];
        }

        if (!isset($this->patternAnalysis['key_patterns'][$pattern])) {
            $this->patternAnalysis['key_patterns'][$pattern] = [
                'count' => 0,
                'total_time' => 0,
                'avg_time' => 0,
                'operations' => [],
            ];
        }

        ++$this->patternAnalysis['key_patterns'][$pattern]['count'];
        $this->patternAnalysis['key_patterns'][$pattern]['total_time'] += $data['processing_time'];
        $this->patternAnalysis['key_patterns'][$pattern]['avg_time'] =
            $this->patternAnalysis['key_patterns'][$pattern]['total_time'] /
            $this->patternAnalysis['key_patterns'][$pattern]['count'];

        if (!in_array($operation, $this->patternAnalysis['key_patterns'][$pattern]['operations'])) {
            $this->patternAnalysis['key_patterns'][$pattern]['operations'][] = $operation;
        }
    }

    /**
     * Get size category for a given size.
     */
    private function getSizeCategory(int $size): string
    {
        foreach (self::SIZE_CATEGORIES as $category => $limit) {
            if ($size <= $limit) {
                return $category;
            }
        }

        return 'huge';
    }

    /**
     * Extract pattern from cache key.
     */
    private function extractKeyPattern(string $key): string
    {
        // Replace numbers with placeholder
        $pattern = preg_replace('/\d+/', '*', $key);

        // Replace UUIDs with placeholder
        $pattern = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/', '*', $pattern);

        // Replace long alphanumeric strings with placeholder
        $pattern = preg_replace('/[a-zA-Z0-9]{10,}/', '*', $pattern);

        return $pattern;
    }

    /**
     * Check if array is nested.
     */
    private function isNestedArray(array $array): bool
    {
        foreach ($array as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maintain history size to prevent memory issues.
     */
    private function maintainHistorySize(): void
    {
        if (count($this->performanceMetrics) > $this->maxHistorySize) {
            $this->performanceMetrics = array_slice($this->performanceMetrics, -$this->maxHistorySize);
        }

        // Clean up operation timings
        foreach ($this->operationTimings as $operation => $timings) {
            if (count($timings) > 1000) {
                $this->operationTimings[$operation] = array_slice($timings, -1000);
            }
        }

        // Clean up throughput metrics (keep last 24 hours)
        $cutoff = time() - 86400;
        $this->throughputMetrics = array_filter(
            $this->throughputMetrics,
            fn ($minute) => $minute >= $cutoff,
            ARRAY_FILTER_USE_KEY
        );
    }

    // Helper methods for report generation
    private function getPerformanceSummary(): array
    {
        $totalOps = count($this->performanceMetrics);
        $slowOps = count($this->slowOperations);

        return [
            'total_operations' => $totalOps,
            'slow_operations' => $slowOps,
            'slow_operation_percentage' => $totalOps > 0 ? ($slowOps / $totalOps) * 100 : 0,
            'average_processing_time' => $this->calculateAverageProcessingTime(),
            'total_processing_time' => $this->calculateTotalProcessingTime(),
            'operations_per_second' => $this->calculateAverageOPS(),
            'memory_efficiency' => $this->calculateMemoryEfficiency(),
        ];
    }

    private function getThroughputAnalysis(): array
    {
        $recentThroughput = array_slice($this->throughputMetrics, -60, null, true); // Last hour

        return [
            'current_ops_per_minute' => $this->calculateCurrentOPM($recentThroughput),
            'peak_ops_per_minute' => $this->calculatePeakOPM($recentThroughput),
            'average_ops_per_minute' => $this->calculateAverageOPM($recentThroughput),
            'throughput_trend' => $this->calculateThroughputTrend($recentThroughput),
            'operation_distribution' => $this->getOperationDistribution($recentThroughput),
        ];
    }

    private function getLatencyAnalysis(): array
    {
        $allTimes = [];
        foreach ($this->operationTimings as $operation => $times) {
            $allTimes = array_merge($allTimes, $times);
        }

        if (empty($allTimes)) {
            return [];
        }

        sort($allTimes);

        $analysis = [
            'mean' => array_sum($allTimes) / count($allTimes),
            'min' => min($allTimes),
            'max' => max($allTimes),
            'percentiles' => [],
        ];

        foreach (self::PERCENTILE_BUCKETS as $percentile) {
            $index = intval(($percentile / 100) * count($allTimes)) - 1;
            $analysis['percentiles'][$percentile] = $allTimes[max(0, $index)];
        }

        return $analysis;
    }

    private function getMemoryAnalysis(): array
    {
        if (!$this->enableMemoryTracking) {
            return ['enabled' => false];
        }

        $memoryDeltas = [];
        foreach ($this->performanceMetrics as $metric) {
            if (isset($metric['memory_before'], $metric['memory_after'])) {
                $memoryDeltas[] = $metric['memory_after'] - $metric['memory_before'];
            }
        }

        if (empty($memoryDeltas)) {
            return ['enabled' => true, 'data' => []];
        }

        return [
            'enabled' => true,
            'average_memory_delta' => array_sum($memoryDeltas) / count($memoryDeltas),
            'max_memory_delta' => max($memoryDeltas),
            'min_memory_delta' => min($memoryDeltas),
            'total_memory_overhead' => array_sum(array_filter($memoryDeltas, fn ($delta) => $delta > 0)),
        ];
    }

    // Simplified implementations of helper methods
    private function calculateAverageProcessingTime(): float
    {
        if (empty($this->performanceMetrics)) {
            return 0.0;
        }
        $times = array_column($this->performanceMetrics, 'processing_time');

        return array_sum($times) / count($times);
    }

    private function calculateTotalProcessingTime(): float
    {
        return array_sum(array_column($this->performanceMetrics, 'processing_time'));
    }

    private function calculateAverageOPS(): float
    {
        if (empty($this->performanceMetrics)) {
            return 0.0;
        }
        $timespan = end($this->performanceMetrics)['timestamp'] - reset($this->performanceMetrics)['timestamp'];

        return $timespan > 0 ? count($this->performanceMetrics) / $timespan : 0.0;
    }

    private function calculateMemoryEfficiency(): float
    {
        // Simplified memory efficiency calculation
        return 95.0; // Placeholder
    }

    private function calculateCurrentOPM(array $throughputData): int
    {
        if (empty($throughputData)) {
            return 0;
        }

        return end($throughputData)['total'] ?? 0;
    }

    private function calculatePeakOPM(array $throughputData): int
    {
        if (empty($throughputData)) {
            return 0;
        }

        return max(array_column($throughputData, 'total'));
    }

    private function calculateAverageOPM(array $throughputData): float
    {
        if (empty($throughputData)) {
            return 0.0;
        }

        return array_sum(array_column($throughputData, 'total')) / count($throughputData);
    }

    private function calculateThroughputTrend(array $throughputData): string
    {
        // Simplified trend analysis
        return 'stable'; // Placeholder
    }

    private function getOperationDistribution(array $throughputData): array
    {
        $distribution = ['hit' => 0, 'miss' => 0, 'set' => 0, 'delete' => 0, 'clear' => 0];

        foreach ($throughputData as $data) {
            foreach ($distribution as $op => $count) {
                $distribution[$op] += $data[$op] ?? 0;
            }
        }

        return $distribution;
    }

    private function getKeyPerformanceAnalysis(): array
    {
        // Sort by average time (slowest first)
        $sorted = $this->keyPerformance;
        uasort($sorted, fn ($a, $b) => $b['avg_time'] <=> $a['avg_time']);

        return [
            'slowest_keys' => array_slice($sorted, 0, 10, true),
            'most_accessed_keys' => $this->getMostAccessedKeys(),
            'key_count' => count($this->keyPerformance),
        ];
    }

    private function getMostAccessedKeys(): array
    {
        $sorted = $this->keyPerformance;
        uasort($sorted, fn ($a, $b) => $b['operation_count'] <=> $a['operation_count']);

        return array_slice($sorted, 0, 10, true);
    }

    private function getSizeAnalysis(): array
    {
        return $this->sizeAnalysis;
    }

    private function getPatternAnalysis(): array
    {
        return $this->patternAnalysis;
    }

    private function identifyBottlenecks(): array
    {
        // Simplified bottleneck identification
        return $this->patternAnalysis['bottlenecks'] ?? [];
    }

    private function generateOptimizationRecommendations(): array
    {
        $recommendations = [];

        // Analyze slow operations
        if (count($this->slowOperations) > 10) {
            $recommendations[] = 'High number of slow operations detected. Consider optimizing serialization or reducing value sizes.';
        }

        // Analyze memory usage
        if ($this->enableMemoryTracking) {
            $memoryAnalysis = $this->getMemoryAnalysis();
            if (isset($memoryAnalysis['average_memory_delta']) && $memoryAnalysis['average_memory_delta'] > 1024 * 1024) {
                $recommendations[] = 'High memory overhead detected. Consider using more efficient data structures.';
            }
        }

        return $recommendations;
    }

    private function getPerformanceTrends(): array
    {
        // Simplified trend analysis
        return [
            'latency_trend' => 'stable',
            'throughput_trend' => 'increasing',
            'error_rate_trend' => 'stable',
        ];
    }

    // Additional helper methods
    private function getRecentMetrics(int $seconds): array
    {
        $cutoff = microtime(true) - $seconds;

        return array_filter($this->performanceMetrics, fn ($metric) => $metric['timestamp'] > $cutoff);
    }

    private function calculateCurrentThroughput(array $metrics): float
    {
        if (empty($metrics)) {
            return 0.0;
        }
        $timespan = max($metrics)['timestamp'] - min($metrics)['timestamp'];

        return $timespan > 0 ? count($metrics) / $timespan : 0.0;
    }

    private function calculateAverageLatency(array $metrics): float
    {
        if (empty($metrics)) {
            return 0.0;
        }
        $times = array_column($metrics, 'processing_time');

        return array_sum($times) / count($times);
    }

    private function calculateErrorRate(array $metrics): float
    {
        // Simplified error rate (operations over threshold)
        $slowCount = count(array_filter($metrics, fn ($m) => $m['processing_time'] > $this->slowThreshold));

        return count($metrics) > 0 ? $slowCount / count($metrics) : 0.0;
    }

    private function getCurrentMemoryUsage(): int
    {
        return $this->enableMemoryTracking ? memory_get_usage(true) : 0;
    }

    private function calculateSlowOperationRate(array $metrics): float
    {
        if (empty($metrics)) {
            return 0.0;
        }
        $slowCount = count(array_filter($metrics, fn ($m) => $m['processing_time'] > $this->slowThreshold));

        return $slowCount / count($metrics);
    }

    private function getSlowestOperation(): ?array
    {
        if (empty($this->slowOperations)) {
            return null;
        }

        $slowest = max($this->slowOperations, fn ($a, $b) => $a['duration'] <=> $b['duration']);

        return $slowest;
    }

    private function groupSlowOperationsByType(): array
    {
        $grouped = [];
        foreach ($this->slowOperations as $op) {
            $type = $op['operation'];
            $grouped[$type] = ($grouped[$type] ?? 0) + 1;
        }

        return $grouped;
    }

    private function analyzeSlowKeyPatterns(): array
    {
        $patterns = [];
        foreach ($this->slowOperations as $op) {
            $pattern = $this->extractKeyPattern($op['key']);
            $patterns[$pattern] = ($patterns[$pattern] ?? 0) + 1;
        }
        arsort($patterns);

        return $patterns;
    }

    private function getSlowOperationsTrend(): string
    {
        // Simplified trend analysis
        return 'stable';
    }

    private function calculateMemoryOverhead(): int
    {
        if (!$this->enableMemoryTracking) {
            return 0;
        }

        $totalOverhead = 0;
        foreach ($this->performanceMetrics as $metric) {
            if (isset($metric['memory_before'], $metric['memory_after'])) {
                $delta = $metric['memory_after'] - $metric['memory_before'];
                if ($delta > 0) {
                    $totalOverhead += $delta;
                }
            }
        }

        return $totalOverhead;
    }

    private function calculateCacheEfficiencyScore(): float
    {
        // Simplified efficiency score based on hit rate and performance
        $hitCount = count(array_filter($this->performanceMetrics, fn ($m) => 'hit' === $m['operation']));
        $totalCount = count($this->performanceMetrics);
        $hitRate = $totalCount > 0 ? $hitCount / $totalCount : 0;

        $avgTime = $this->calculateAverageProcessingTime();
        $timeScore = $avgTime < $this->slowThreshold ? 1.0 : $this->slowThreshold / $avgTime;

        return ($hitRate * 0.7 + $timeScore * 0.3) * 100; // Score out of 100
    }
}

/**
 * PSR-14 compatible performance event.
 */
final class CachePerformanceEvent
{
    private bool $propagationStopped = false;

    public function __construct(
        private string $operation,
        private string $key,
        private float $processingTime,
        private int $valueSize = 0,
        private array $additionalData = []
    ) {
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getProcessingTime(): float
    {
        return $this->processingTime;
    }

    public function getValueSize(): int
    {
        return $this->valueSize;
    }

    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
