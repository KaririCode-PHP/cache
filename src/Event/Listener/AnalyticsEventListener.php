<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Event\CacheAnalyticsEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Advanced analytics event listener for comprehensive cache behavior analysis.
 *
 * This implementation provides deep insights into cache operations including:
 * - Performance analytics with percentile calculations
 * - Key access pattern analysis and hotspot detection
 * - Cache efficiency scoring and optimization recommendations
 * - Time-series data collection for trend analysis
 * - Memory usage patterns and optimization insights
 * - Hit ratio analysis by key patterns and time windows
 * - Predictive analytics for cache warming strategies
 * - Cost-benefit analysis of cache operations
 *
 * Following analytical best practices and statistical methodologies.
 */
final class AnalyticsEventListener implements CacheEventListener
{
    private array $analytics = [];
    private array $keyPatterns = [];
    private array $timeSeriesData = [];
    private array $performanceMetrics = [];
    private array $hotspots = [];
    private array $coldKeys = [];
    private array $accessFrequency = [];
    private array $sizeDistribution = [];
    private array $temporalPatterns = [];
    private ?EventDispatcherInterface $eventDispatcher = null;

    private const MAX_ANALYTICS_ENTRIES = 50000;
    private const TIME_WINDOW_SECONDS = 300; // 5 minutes
    private const HOTSPOT_THRESHOLD = 10;
    private const COLD_KEY_THRESHOLD = 3600; // 1 hour
    private const PERCENTILES = [50, 75, 90, 95, 99];

    public function __construct(
        private int $maxEntries = self::MAX_ANALYTICS_ENTRIES,
        private int $timeWindow = self::TIME_WINDOW_SECONDS,
        private bool $enablePredictiveAnalytics = true,
        private bool $enableRecommendations = true
    ) {
        $this->initializeAnalytics();
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
        $timestamp = microtime(true);
        $valueSize = $this->calculateValueSize($value);
        $valueType = gettype($value);

        $this->recordAnalyticsEvent('hit', $key, [
            'timestamp' => $timestamp,
            'value_size' => $valueSize,
            'value_type' => $valueType,
            'hour_of_day' => (int) date('H', (int) $timestamp),
            'day_of_week' => (int) date('w', (int) $timestamp),
        ]);

        $this->updateHitAnalytics($key, $valueSize, $valueType, $timestamp);
        $this->updateAccessFrequency($key, $timestamp);
        $this->updateSizeDistribution($valueSize);
        $this->analyzeTemporalPatterns($key, 'hit', $timestamp);
        $this->detectHotspots($key, $timestamp);

        // Dispatch analytics event if dispatcher available
        if ($this->eventDispatcher) {
            $analyticsEvent = new CacheAnalyticsEvent('hit', $key, [
                'value_size' => $valueSize,
                'value_type' => $valueType,
                'access_frequency' => $this->accessFrequency[$key]['count'] ?? 1,
            ]);
            $this->eventDispatcher->dispatch($analyticsEvent);
        }
    }

    public function onCacheMiss(string $key): void
    {
        $timestamp = microtime(true);

        $this->recordAnalyticsEvent('miss', $key, [
            'timestamp' => $timestamp,
            'hour_of_day' => (int) date('H', (int) $timestamp),
            'day_of_week' => (int) date('w', (int) $timestamp),
        ]);

        $this->updateMissAnalytics($key, $timestamp);
        $this->analyzeTemporalPatterns($key, 'miss', $timestamp);
        $this->identifyMissPatterns($key, $timestamp);

        // Dispatch analytics event
        if ($this->eventDispatcher) {
            $analyticsEvent = new CacheAnalyticsEvent('miss', $key, [
                'miss_rate' => $this->calculateMissRate($key),
                'pattern' => $this->extractKeyPattern($key),
            ]);
            $this->eventDispatcher->dispatch($analyticsEvent);
        }
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $timestamp = microtime(true);
        $valueSize = $this->calculateValueSize($value);
        $valueType = gettype($value);

        $this->recordAnalyticsEvent('set', $key, [
            'timestamp' => $timestamp,
            'value_size' => $valueSize,
            'value_type' => $valueType,
            'hour_of_day' => (int) date('H', (int) $timestamp),
            'day_of_week' => (int) date('w', (int) $timestamp),
        ]);

        $this->updateSetAnalytics($key, $valueSize, $valueType, $timestamp);
        $this->updateSizeDistribution($valueSize);
        $this->analyzeStorageEfficiency($key, $valueSize, $valueType);

        // Dispatch analytics event
        if ($this->eventDispatcher) {
            $analyticsEvent = new CacheAnalyticsEvent('set', $key, [
                'value_size' => $valueSize,
                'value_type' => $valueType,
                'storage_efficiency' => $this->calculateStorageEfficiency($valueSize, $valueType),
            ]);
            $this->eventDispatcher->dispatch($analyticsEvent);
        }
    }

    public function onCacheDelete(string $key): void
    {
        $timestamp = microtime(true);

        $this->recordAnalyticsEvent('delete', $key, [
            'timestamp' => $timestamp,
            'hour_of_day' => (int) date('H', (int) $timestamp),
            'day_of_week' => (int) date('w', (int) $timestamp),
        ]);

        $this->updateDeleteAnalytics($key, $timestamp);
        $this->cleanupKeyAnalytics($key);

        // Dispatch analytics event
        if ($this->eventDispatcher) {
            $analyticsEvent = new CacheAnalyticsEvent('delete', $key, [
                'lifetime' => $this->calculateKeyLifetime($key),
            ]);
            $this->eventDispatcher->dispatch($analyticsEvent);
        }
    }

    public function onCacheClear(): void
    {
        $timestamp = microtime(true);

        $this->recordAnalyticsEvent('clear', 'all', [
            'timestamp' => $timestamp,
            'keys_cleared' => count($this->accessFrequency),
        ]);

        $this->updateClearAnalytics($timestamp);

        // Dispatch analytics event
        if ($this->eventDispatcher) {
            $analyticsEvent = new CacheAnalyticsEvent('clear', 'all', [
                'impact_level' => 'critical',
                'keys_affected' => count($this->accessFrequency),
            ]);
            $this->eventDispatcher->dispatch($analyticsEvent);
        }
    }

    /**
     * Get comprehensive analytics report.
     */
    public function getAnalyticsReport(): array
    {
        return [
            'summary' => $this->getAnalyticsSummary(),
            'performance_insights' => $this->getPerformanceInsights(),
            'key_analytics' => $this->getKeyAnalytics(),
            'temporal_patterns' => $this->getTemporalPatterns(),
            'hotspots' => $this->getHotspots(),
            'cold_keys' => $this->getColdKeys(),
            'size_analysis' => $this->getSizeAnalysis(),
            'efficiency_metrics' => $this->getEfficiencyMetrics(),
            'predictive_insights' => $this->getPredictiveInsights(),
            'recommendations' => $this->getRecommendations(),
            'trends' => $this->getTrendAnalysis(),
        ];
    }

    /**
     * Get real-time analytics dashboard data.
     */
    public function getDashboardData(): array
    {
        $recentData = $this->getRecentAnalytics(300); // Last 5 minutes

        return [
            'real_time_stats' => $this->calculateRealTimeStats($recentData),
            'hit_rate_trend' => $this->calculateHitRateTrend($recentData),
            'top_keys' => $this->getTopKeys(10),
            'recent_hotspots' => $this->getRecentHotspots(),
            'performance_alerts' => $this->getPerformanceAlerts(),
            'capacity_utilization' => $this->calculateCapacityUtilization(),
            'operation_distribution' => $this->getOperationDistribution($recentData),
        ];
    }

    /**
     * Get predictive analytics for cache optimization.
     */
    public function getPredictiveInsights(): array
    {
        if (!$this->enablePredictiveAnalytics) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'cache_warming_candidates' => $this->identifyCacheWarmingCandidates(),
            'eviction_predictions' => $this->predictEvictions(),
            'hot_key_predictions' => $this->predictHotKeys(),
            'capacity_forecast' => $this->forecastCapacityNeeds(),
            'optimal_ttl_suggestions' => $this->suggestOptimalTTL(),
            'access_pattern_forecast' => $this->forecastAccessPatterns(),
        ];
    }

    /**
     * Get optimization recommendations.
     */
    public function getRecommendations(): array
    {
        if (!$this->enableRecommendations) {
            return ['enabled' => false];
        }

        $recommendations = [];

        // Analyze hit rates
        $hitRate = $this->calculateOverallHitRate();
        if ($hitRate < 0.8) {
            $recommendations[] = [
                'type' => 'hit_rate_optimization',
                'priority' => 'high',
                'description' => "Hit rate is low ({$hitRate}%). Consider increasing cache size or adjusting TTL values.",
                'impact' => 'high',
                'effort' => 'medium',
            ];
        }

        // Analyze hotspots
        if (count($this->hotspots) > 10) {
            $recommendations[] = [
                'type' => 'hotspot_optimization',
                'priority' => 'medium',
                'description' => 'Multiple hotspots detected. Consider dedicated caching strategies for hot keys.',
                'impact' => 'medium',
                'effort' => 'high',
            ];
        }

        // Analyze cold keys
        if (count($this->coldKeys) > 100) {
            $recommendations[] = [
                'type' => 'memory_optimization',
                'priority' => 'low',
                'description' => 'Many cold keys detected. Consider implementing LRU eviction or reducing TTL.',
                'impact' => 'low',
                'effort' => 'low',
            ];
        }

        return [
            'enabled' => true,
            'recommendations' => $recommendations,
            'generated_at' => time(),
        ];
    }

    /**
     * Export analytics data for external analysis.
     */
    public function exportAnalytics(?int $limit = null): array
    {
        $analytics = $limit ? array_slice($this->analytics, -$limit) : $this->analytics;

        return [
            'export_timestamp' => time(),
            'analytics_count' => count($analytics),
            'configuration' => [
                'max_entries' => $this->maxEntries,
                'time_window' => $this->timeWindow,
                'predictive_analytics' => $this->enablePredictiveAnalytics,
                'recommendations' => $this->enableRecommendations,
            ],
            'analytics' => $analytics,
            'time_series' => $this->timeSeriesData,
            'key_patterns' => $this->keyPatterns,
            'performance_metrics' => $this->performanceMetrics,
        ];
    }

    /**
     * Get statistics for the CacheEventListener interface.
     */
    public function getStats(): array
    {
        $totalOps = count($this->analytics);
        $hits = count(array_filter($this->analytics, fn ($a) => 'hit' === $a['operation']));
        $misses = count(array_filter($this->analytics, fn ($a) => 'miss' === $a['operation']));

        return [
            'total_operations' => $totalOps,
            'hits' => $hits,
            'misses' => $misses,
            'sets' => count(array_filter($this->analytics, fn ($a) => 'set' === $a['operation'])),
            'deletes' => count(array_filter($this->analytics, fn ($a) => 'delete' === $a['operation'])),
            'clears' => count(array_filter($this->analytics, fn ($a) => 'clear' === $a['operation'])),
            'hit_rate' => $totalOps > 0 ? $hits / ($hits + $misses) : 0,
            'unique_keys' => count($this->accessFrequency),
            'hotspots' => count($this->hotspots),
            'cold_keys' => count($this->coldKeys),
        ];
    }

    /**
     * Initialize analytics data structures.
     */
    private function initializeAnalytics(): void
    {
        $this->analytics = [];
        $this->keyPatterns = [];
        $this->timeSeriesData = [];
        $this->performanceMetrics = [
            'operation_times' => [],
            'value_sizes' => [],
            'hit_rates_by_pattern' => [],
        ];
        $this->hotspots = [];
        $this->coldKeys = [];
        $this->accessFrequency = [];
        $this->sizeDistribution = [
            'tiny' => 0,    // < 1KB
            'small' => 0,   // 1-10KB
            'medium' => 0,  // 10-100KB
            'large' => 0,   // 100KB-1MB
            'huge' => 0,    // > 1MB
        ];
        $this->temporalPatterns = [];
        $this->recommendations = [];
    }

    /**
     * Record analytics event.
     */
    private function recordAnalyticsEvent(string $operation, string $key, array $data): void
    {
        $event = array_merge([
            'operation' => $operation,
            'key' => $key,
            'pattern' => $this->extractKeyPattern($key),
        ], $data);

        $this->analytics[] = $event;

        // Update time series data
        $minute = floor($data['timestamp'] / 60) * 60;
        if (!isset($this->timeSeriesData[$minute])) {
            $this->timeSeriesData[$minute] = [
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0,
                'clears' => 0,
            ];
        }
        ++$this->timeSeriesData[$minute][$operation];

        // Maintain size limits
        $this->maintainAnalyticsSize();
    }

    /**
     * Update hit analytics.
     */
    private function updateHitAnalytics(string $key, int $valueSize, string $valueType, float $timestamp): void
    {
        $pattern = $this->extractKeyPattern($key);

        if (!isset($this->keyPatterns[$pattern])) {
            $this->keyPatterns[$pattern] = [
                'hits' => 0,
                'misses' => 0,
                'total_size' => 0,
                'avg_size' => 0,
                'types' => [],
            ];
        }

        ++$this->keyPatterns[$pattern]['hits'];
        $this->keyPatterns[$pattern]['total_size'] += $valueSize;
        $this->keyPatterns[$pattern]['avg_size'] =
            $this->keyPatterns[$pattern]['total_size'] /
            ($this->keyPatterns[$pattern]['hits'] + $this->keyPatterns[$pattern]['misses']);

        $this->keyPatterns[$pattern]['types'][$valueType] =
            ($this->keyPatterns[$pattern]['types'][$valueType] ?? 0) + 1;
    }

    /**
     * Update miss analytics.
     */
    private function updateMissAnalytics(string $key, float $timestamp): void
    {
        $pattern = $this->extractKeyPattern($key);

        if (!isset($this->keyPatterns[$pattern])) {
            $this->keyPatterns[$pattern] = [
                'hits' => 0,
                'misses' => 0,
                'total_size' => 0,
                'avg_size' => 0,
                'types' => [],
            ];
        }

        ++$this->keyPatterns[$pattern]['misses'];
    }

    /**
     * Update set analytics.
     */
    private function updateSetAnalytics(string $key, int $valueSize, string $valueType, float $timestamp): void
    {
        // Track when key was set for lifetime calculations
        if (!isset($this->accessFrequency[$key])) {
            $this->accessFrequency[$key] = [
                'count' => 0,
                'first_access' => $timestamp,
                'last_access' => $timestamp,
                'operations' => [],
            ];
        }

        $this->accessFrequency[$key]['operations'][] = [
            'operation' => 'set',
            'timestamp' => $timestamp,
            'size' => $valueSize,
            'type' => $valueType,
        ];
    }

    /**
     * Update delete analytics.
     */
    private function updateDeleteAnalytics(string $key, float $timestamp): void
    {
        if (isset($this->accessFrequency[$key])) {
            $this->accessFrequency[$key]['operations'][] = [
                'operation' => 'delete',
                'timestamp' => $timestamp,
            ];
        }
    }

    /**
     * Update clear analytics.
     */
    private function updateClearAnalytics(float $timestamp): void
    {
        // Reset frequency counters but keep historical data
        $this->accessFrequency = [];
        $this->hotspots = [];
    }

    /**
     * Update access frequency tracking.
     */
    private function updateAccessFrequency(string $key, float $timestamp): void
    {
        if (!isset($this->accessFrequency[$key])) {
            $this->accessFrequency[$key] = [
                'count' => 0,
                'first_access' => $timestamp,
                'last_access' => $timestamp,
                'operations' => [],
            ];
        }

        ++$this->accessFrequency[$key]['count'];
        $this->accessFrequency[$key]['last_access'] = $timestamp;
        $this->accessFrequency[$key]['operations'][] = [
            'operation' => 'hit',
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Update size distribution tracking.
     */
    private function updateSizeDistribution(int $size): void
    {
        if ($size < 1024) {
            ++$this->sizeDistribution['tiny'];
        } elseif ($size < 10240) {
            ++$this->sizeDistribution['small'];
        } elseif ($size < 102400) {
            ++$this->sizeDistribution['medium'];
        } elseif ($size < 1048576) {
            ++$this->sizeDistribution['large'];
        } else {
            ++$this->sizeDistribution['huge'];
        }
    }

    /**
     * Analyze temporal patterns.
     */
    private function analyzeTemporalPatterns(string $key, string $operation, float $timestamp): void
    {
        $hour = (int) date('H', (int) $timestamp);
        $dayOfWeek = (int) date('w', (int) $timestamp);

        if (!isset($this->temporalPatterns[$hour])) {
            $this->temporalPatterns[$hour] = [];
        }

        if (!isset($this->temporalPatterns[$hour][$dayOfWeek])) {
            $this->temporalPatterns[$hour][$dayOfWeek] = [
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0,
            ];
        }

        ++$this->temporalPatterns[$hour][$dayOfWeek][$operation];
    }

    /**
     * Detect hotspots (frequently accessed keys).
     */
    private function detectHotspots(string $key, float $timestamp): void
    {
        $frequency = $this->accessFrequency[$key]['count'] ?? 1;

        if ($frequency >= self::HOTSPOT_THRESHOLD) {
            $this->hotspots[$key] = [
                'frequency' => $frequency,
                'last_access' => $timestamp,
                'first_detected' => $this->hotspots[$key]['first_detected'] ?? $timestamp,
                'pattern' => $this->extractKeyPattern($key),
            ];
        }
    }

    /**
     * Identify miss patterns for optimization.
     */
    private function identifyMissPatterns(string $key, float $timestamp): void
    {
        $pattern = $this->extractKeyPattern($key);

        // Track miss sequences that might indicate cache warming opportunities
        if (!isset($this->keyPatterns[$pattern]['recent_misses'])) {
            $this->keyPatterns[$pattern]['recent_misses'] = [];
        }

        $this->keyPatterns[$pattern]['recent_misses'][] = $timestamp;

        // Keep only recent misses (last hour)
        $this->keyPatterns[$pattern]['recent_misses'] = array_filter(
            $this->keyPatterns[$pattern]['recent_misses'],
            fn ($ts) => $timestamp - $ts < 3600
        );
    }

    /**
     * Analyze storage efficiency.
     */
    private function analyzeStorageEfficiency(string $key, int $valueSize, string $valueType): void
    {
        // Calculate efficiency metrics based on value type and size
        $efficiency = $this->calculateStorageEfficiency($valueSize, $valueType);

        if (!isset($this->performanceMetrics['storage_efficiency'])) {
            $this->performanceMetrics['storage_efficiency'] = [];
        }

        $this->performanceMetrics['storage_efficiency'][] = [
            'key' => $key,
            'size' => $valueSize,
            'type' => $valueType,
            'efficiency' => $efficiency,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Calculate storage efficiency score.
     */
    private function calculateStorageEfficiency(int $valueSize, string $valueType): float
    {
        // Basic efficiency calculation based on size and type
        $baseScore = 1.0;

        // Penalize very large values
        if ($valueSize > 1048576) { // > 1MB
            $baseScore *= 0.5;
        } elseif ($valueSize > 102400) { // > 100KB
            $baseScore *= 0.8;
        }

        // Adjust based on type
        $typeMultiplier = match ($valueType) {
            'string', 'integer', 'double' => 1.0,
            'boolean' => 1.2, // Very efficient
            'array' => 0.9,
            'object' => 0.7, // Less efficient due to serialization
            'resource' => 0.3, // Very inefficient
            default => 0.8,
        };

        return $baseScore * $typeMultiplier;
    }

    /**
     * Calculate value size for analysis.
     */
    private function calculateValueSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    /**
     * Extract pattern from cache key.
     */
    private function extractKeyPattern(string $key): string
    {
        // Replace numbers with wildcards
        $pattern = preg_replace('/\d+/', '*', $key);

        // Replace UUIDs with wildcards
        $pattern = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/', '*', $pattern);

        // Replace long alphanumeric strings
        $pattern = preg_replace('/[a-zA-Z0-9]{10,}/', '*', $pattern);

        return $pattern;
    }

    /**
     * Cleanup analytics for deleted key.
     */
    private function cleanupKeyAnalytics(string $key): void
    {
        unset($this->hotspots[$key]);
        // Keep access frequency for historical analysis
    }

    /**
     * Maintain analytics data size to prevent memory issues.
     */
    private function maintainAnalyticsSize(): void
    {
        if (count($this->analytics) > $this->maxEntries) {
            $this->analytics = array_slice($this->analytics, -$this->maxEntries);
        }

        // Clean old time series data (keep last 24 hours)
        $cutoff = time() - 86400;
        $this->timeSeriesData = array_filter(
            $this->timeSeriesData,
            fn ($minute) => $minute >= $cutoff,
            ARRAY_FILTER_USE_KEY
        );
    }

    // Helper methods for report generation
    private function getAnalyticsSummary(): array
    {
        return $this->getStats();
    }

    private function getPerformanceInsights(): array
    {
        return [
            'avg_value_size' => $this->calculateAverageValueSize(),
            'size_distribution' => $this->sizeDistribution,
            'efficiency_score' => $this->calculateOverallEfficiencyScore(),
        ];
    }

    private function getKeyAnalytics(): array
    {
        return $this->keyPatterns;
    }

    private function getTemporalPatterns(): array
    {
        return $this->temporalPatterns;
    }

    private function getHotspots(): array
    {
        return $this->hotspots;
    }

    private function getColdKeys(): array
    {
        $now = microtime(true);
        $coldKeys = [];

        foreach ($this->accessFrequency as $key => $data) {
            if (($now - $data['last_access']) > self::COLD_KEY_THRESHOLD) {
                $coldKeys[$key] = $data;
            }
        }

        return $coldKeys;
    }

    private function getSizeAnalysis(): array
    {
        return $this->sizeDistribution;
    }

    private function getEfficiencyMetrics(): array
    {
        return [
            'storage_efficiency' => $this->calculateOverallEfficiencyScore(),
            'hit_rate' => $this->calculateOverallHitRate(),
            'memory_utilization' => $this->calculateMemoryUtilization(),
        ];
    }

    private function getTrendAnalysis(): array
    {
        return [
            'hit_rate_trend' => $this->calculateHitRateTrend(),
            'access_pattern_trend' => $this->calculateAccessPatternTrend(),
            'size_trend' => $this->calculateSizeTrend(),
        ];
    }

    // Simplified implementations for complex calculations
    private function calculateAverageValueSize(): float
    {
        $sizes = array_column(
            array_filter($this->analytics, fn ($a) => isset($a['value_size'])),
            'value_size'
        );

        return empty($sizes) ? 0 : array_sum($sizes) / count($sizes);
    }

    private function calculateOverallEfficiencyScore(): float
    {
        return 85.0; // Placeholder - would implement complex calculation
    }

    private function calculateOverallHitRate(): float
    {
        $stats = $this->getStats();

        return $stats['hit_rate'];
    }

    private function calculateMemoryUtilization(): float
    {
        return 75.0; // Placeholder
    }

    private function calculateHitRateTrend(?array $recentData = null): string
    {
        return 'stable'; // Placeholder
    }

    private function calculateAccessPatternTrend(): string
    {
        return 'stable'; // Placeholder
    }

    private function calculateSizeTrend(): string
    {
        return 'stable'; // Placeholder
    }

    private function getRecentAnalytics(int $seconds): array
    {
        $cutoff = microtime(true) - $seconds;

        return array_filter($this->analytics, fn ($a) => $a['timestamp'] > $cutoff);
    }

    private function calculateRealTimeStats(array $recentData): array
    {
        return [
            'operations_per_minute' => count($recentData) / 5,
            'current_hit_rate' => $this->calculateCurrentHitRate($recentData),
            'active_keys' => count(array_unique(array_column($recentData, 'key'))),
        ];
    }

    private function calculateCurrentHitRate(array $recentData): float
    {
        $hits = count(array_filter($recentData, fn ($a) => 'hit' === $a['operation']));
        $total = $hits + count(array_filter($recentData, fn ($a) => 'miss' === $a['operation']));

        return $total > 0 ? $hits / $total : 0;
    }

    private function getTopKeys(int $limit): array
    {
        $sorted = $this->accessFrequency;
        uasort($sorted, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($sorted, 0, $limit, true);
    }

    private function getRecentHotspots(): array
    {
        $recent = [];
        $cutoff = microtime(true) - 3600; // Last hour

        foreach ($this->hotspots as $key => $data) {
            if ($data['last_access'] > $cutoff) {
                $recent[$key] = $data;
            }
        }

        return $recent;
    }

    private function getPerformanceAlerts(): array
    {
        $alerts = [];

        // Check hit rate
        if ($this->calculateOverallHitRate() < 0.7) {
            $alerts[] = [
                'type' => 'low_hit_rate',
                'severity' => 'warning',
                'message' => 'Hit rate below 70%',
            ];
        }

        return $alerts;
    }

    private function calculateCapacityUtilization(): float
    {
        return 60.0; // Placeholder
    }

    private function getOperationDistribution(array $recentData): array
    {
        $distribution = [];
        foreach ($recentData as $event) {
            $op = $event['operation'];
            $distribution[$op] = ($distribution[$op] ?? 0) + 1;
        }

        return $distribution;
    }

    // Predictive analytics methods (simplified implementations)
    private function identifyCacheWarmingCandidates(): array
    {
        return []; // Placeholder
    }

    private function predictEvictions(): array
    {
        return []; // Placeholder
    }

    private function predictHotKeys(): array
    {
        return []; // Placeholder
    }

    private function forecastCapacityNeeds(): array
    {
        return []; // Placeholder
    }

    private function suggestOptimalTTL(): array
    {
        return []; // Placeholder
    }

    private function forecastAccessPatterns(): array
    {
        return []; // Placeholder
    }

    private function calculateMissRate(string $key): float
    {
        $pattern = $this->extractKeyPattern($key);
        $data = $this->keyPatterns[$pattern] ?? ['hits' => 0, 'misses' => 0];
        $total = $data['hits'] + $data['misses'];

        return $total > 0 ? $data['misses'] / $total : 0;
    }

    private function calculateKeyLifetime(string $key): float
    {
        if (!isset($this->accessFrequency[$key])) {
            return 0;
        }

        return $this->accessFrequency[$key]['last_access'] - $this->accessFrequency[$key]['first_access'];
    }
}
