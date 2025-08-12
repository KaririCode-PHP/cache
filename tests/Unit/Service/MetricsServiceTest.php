<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Service\MetricsService;
use KaririCode\Contract\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive unit tests for the MetricsService.
 *
 * This test suite validates all public methods and edge cases of the MetricsService,
 * ensuring proper metrics collection, calculation accuracy, performance monitoring,
 * and reset functionality according to cache operation requirements.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       MetricsService The class under test
 */
#[CoversClass(MetricsService::class)]
final class MetricsServiceTest extends TestCase
{
    private MetricsService $service;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->service = new MetricsService($this->logger);
    }

    // ========================================================================
    // Constructor and Initialization Tests
    // ========================================================================

    /**
     * Tests that the service can be created without a logger.
     */
    public function testCanBeCreatedWithoutLogger(): void
    {
        // Act
        $service = new MetricsService();

        // Assert
        $this->assertInstanceOf(MetricsService::class, $service);

        // Verify initial state
        $metrics = $service->getMetrics();
        $this->assertIsArray($metrics);
        $this->assertSame(0.0, $metrics['hit_rate']);
        $this->assertSame(0, $metrics['total_operations']);
    }

    /**
     * Tests that the service can be created with a custom logger.
     */
    public function testCanBeCreatedWithCustomLogger(): void
    {
        // Arrange
        $logger = $this->createMock(Logger::class);

        // Act
        $service = new MetricsService($logger);

        // Assert
        $this->assertInstanceOf(MetricsService::class, $service);
    }

    /**
     * Tests initial state has all expected counters set to zero.
     */
    public function testInitialStateHasAllCountersAtZero(): void
    {
        // Act
        $metrics = $this->service->getMetrics();

        // Assert
        $this->assertArrayHasKey('counters', $metrics);
        $counters = $metrics['counters'];

        $expectedCounters = [
            'get', 'set', 'delete', 'clear', 'has',
            'getMultiple', 'setMultiple', 'deleteMultiple',
            'increment', 'decrement', 'add', 'hits', 'misses', 'errors',
        ];

        foreach ($expectedCounters as $counter) {
            $this->assertArrayHasKey($counter, $counters);
            $this->assertSame(0, $counters[$counter]);
        }
    }

    /**
     * Tests initial uptime is near zero but positive.
     */
    public function testInitialUptimeIsPositive(): void
    {
        // Act
        $metrics = $this->service->getMetrics();

        // Assert
        $this->assertArrayHasKey('uptime_seconds', $metrics);
        $this->assertGreaterThanOrEqual(0.0, $metrics['uptime_seconds']);
        $this->assertLessThan(1.0, $metrics['uptime_seconds']); // Should be very small initially
    }

    // ========================================================================
    // Basic Recording Tests - Happy Path
    // ========================================================================

    /**
     * Tests recording various operations updates counters correctly.
     *
     * @param string $operation Operation name to test
     */
    #[DataProvider('basicOperationProvider')]
    public function testRecordOperationUpdatesCounters(string $operation): void
    {
        // Act
        $this->service->record($operation, 0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(1, $metrics['counters'][$operation]);
        $this->assertSame(0, $metrics['counters']['errors']); // Success = no errors
    }

    /**
     * Tests recording successful operations multiple times.
     */
    public function testRecordMultipleSuccessfulOperations(): void
    {
        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('get', 0.002, true);
        $this->service->record('set', 0.003, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(2, $metrics['counters']['get']);
        $this->assertSame(1, $metrics['counters']['set']);
        $this->assertSame(0, $metrics['counters']['errors']);
    }

    /**
     * Tests recording operations with failures increments error counter.
     */
    public function testRecordFailedOperationsIncrementsErrors(): void
    {
        // Act
        $this->service->record('get', 0.001, false);
        $this->service->record('set', 0.002, false);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(1, $metrics['counters']['get']);
        $this->assertSame(1, $metrics['counters']['set']);
        $this->assertSame(2, $metrics['counters']['errors']); // Two failures
    }

    /**
     * Tests recording unknown operations creates new counter.
     */
    public function testRecordUnknownOperationCreatesNewCounter(): void
    {
        // Act
        $this->service->record('custom_operation', 0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertArrayHasKey('custom_operation', $metrics['counters']);
        $this->assertSame(1, $metrics['counters']['custom_operation']);
    }

    /**
     * Tests recording unknown operation with detailed metrics enabled initializes durations array.
     */
    public function testRecordUnknownOperationWithDetailedMetricsInitializesDurations(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act
        $this->service->record('custom_operation', 0.005, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertArrayHasKey('custom_operation', $metrics['counters']);
        $this->assertSame(1, $metrics['counters']['custom_operation']);

        // Verify performance metrics are created for the new operation
        $this->assertArrayHasKey('performance', $metrics);
        $this->assertArrayHasKey('custom_operation', $metrics['performance']);

        $customPerformance = $metrics['performance']['custom_operation'];
        $this->assertSame(1, $customPerformance['count']);
        $this->assertSame(5.0, $customPerformance['avg_ms']); // 0.005 * 1000 = 5ms
    }

    // ========================================================================
    // Hit Rate Calculation Tests
    // ========================================================================

    /**
     * Tests hit rate calculation with only hits.
     */
    public function testHitRateCalculationWithOnlyHits(): void
    {
        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('getMultiple', 0.002, true);
        $this->service->record('hits', 0.001, true);
        $this->service->record('hits', 0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(1.0, $metrics['hit_rate']); // 2 hits out of 2 reads
    }

    /**
     * Tests hit rate calculation with mixed hits and misses.
     */
    public function testHitRateCalculationWithMixedResults(): void
    {
        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('get', 0.001, true);
        $this->service->record('get', 0.001, true);
        $this->service->record('getMultiple', 0.002, true);
        $this->service->record('hits', 0.001, true);
        $this->service->record('hits', 0.001, true);
        $this->service->record('hits', 0.001, true); // 3 hits out of 4 reads

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(0.75, $metrics['hit_rate']); // 3/4 = 0.75
    }

    /**
     * Tests hit rate is zero when there are no read operations.
     */
    public function testHitRateIsZeroWithNoReads(): void
    {
        // Act
        $this->service->record('set', 0.001, true);
        $this->service->record('delete', 0.002, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(0.0, $metrics['hit_rate']);
    }

    /**
     * Tests hit rate is zero when there are reads but no hits.
     */
    public function testHitRateIsZeroWithReadsButNoHits(): void
    {
        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('getMultiple', 0.002, true);
        // No hits recorded

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(0.0, $metrics['hit_rate']);
    }

    // ========================================================================
    // Total Operations Calculation Tests
    // ========================================================================

    /**
     * Tests total operations excludes hits and misses counters.
     */
    public function testTotalOperationsExcludesHitsAndMisses(): void
    {
        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('set', 0.002, true);
        $this->service->record('hits', 0.001, true);
        $this->service->record('misses', 0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        // Total should be 2 (get + set), not 4 (excluding hits and misses)
        $this->assertSame(2, $metrics['total_operations']);
    }

    /**
     * Tests total operations calculation with various operation types.
     */
    public function testTotalOperationsWithVariousTypes(): void
    {
        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('set', 0.002, true);
        $this->service->record('delete', 0.003, true);
        $this->service->record('clear', 0.004, true);
        $this->service->record('has', 0.005, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(5, $metrics['total_operations']);
    }

    // ========================================================================
    // Detailed Metrics Tests
    // ========================================================================

    /**
     * Tests enabling detailed metrics allows performance tracking.
     */
    public function testEnableDetailedMetricsEnablesPerformanceTracking(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act
        $this->service->record('get', 0.001, true);
        $this->service->record('get', 0.002, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertArrayHasKey('performance', $metrics);
        $this->assertArrayHasKey('get', $metrics['performance']);

        $getPerformance = $metrics['performance']['get'];
        $this->assertSame(2, $getPerformance['count']);
        $this->assertEqualsWithDelta(1.5, $getPerformance['avg_ms'], 0.1); // Average of 1ms and 2ms
    }

    /**
     * Tests detailed metrics calculates percentiles correctly.
     */
    public function testDetailedMetricsCalculatesPercentilesCorrectly(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Add multiple data points for percentile calculation
        $durations = [0.001, 0.002, 0.003, 0.004, 0.005, 0.006, 0.007, 0.008, 0.009, 0.010];

        // Act
        foreach ($durations as $duration) {
            $this->service->record('get', $duration, true);
        }

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertArrayHasKey('performance', $metrics);
        $getPerformance = $metrics['performance']['get'];

        $this->assertArrayHasKey('p50_ms', $getPerformance);
        $this->assertArrayHasKey('p95_ms', $getPerformance);
        $this->assertArrayHasKey('p99_ms', $getPerformance);

        // P50 (median) should be around 5-6ms for our dataset
        $this->assertGreaterThan(4.0, $getPerformance['p50_ms']);
        $this->assertLessThan(7.0, $getPerformance['p50_ms']);
    }

    /**
     * Tests detailed metrics without enabling returns no performance data.
     */
    public function testDetailedMetricsDisabledByDefault(): void
    {
        // Act
        $this->service->record('get', 0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertArrayNotHasKey('performance', $metrics);
    }

    /**
     * Tests detailed metrics memory management (keeps last 1000 records).
     */
    public function testDetailedMetricsMemoryManagement(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act - Record more than 1000 operations
        for ($i = 0; $i < 1050; ++$i) {
            $this->service->record('get', 0.001, true);
        }

        // Assert
        $metrics = $this->service->getMetrics();
        $getPerformance = $metrics['performance']['get'];

        // The count should reflect all operations recorded, but internal duration array is capped at 1000
        $this->assertSame(1050, $getPerformance['count']);
        $this->assertGreaterThan(0, $getPerformance['avg_ms']);

        // The actual number of duration records internally is capped, but count shows total operations
        $this->assertArrayHasKey('p50_ms', $getPerformance);
        $this->assertArrayHasKey('p95_ms', $getPerformance);
        $this->assertArrayHasKey('p99_ms', $getPerformance);
    }

    // ========================================================================
    // Reset Functionality Tests
    // ========================================================================

    /**
     * Tests reset clears all counters.
     */
    public function testResetClearsAllCounters(): void
    {
        // Arrange
        $this->service->record('get', 0.001, true);
        $this->service->record('set', 0.002, true);
        $this->service->record('hits', 0.001, true);

        // Act
        $this->service->reset();

        // Assert
        $metrics = $this->service->getMetrics();
        foreach ($metrics['counters'] as $count) {
            $this->assertSame(0, $count);
        }
    }

    /**
     * Tests reset clears performance data when detailed metrics enabled.
     */
    public function testResetClearsPerformanceData(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();
        $this->service->record('get', 0.001, true);
        $this->service->record('set', 0.002, true);

        // Verify performance data exists before reset
        $beforeReset = $this->service->getMetrics();
        $this->assertArrayHasKey('performance', $beforeReset);

        // Act
        $this->service->reset();

        // Assert
        $afterReset = $this->service->getMetrics();
        // After reset, performance key should not exist since no operations have been recorded
        $this->assertArrayNotHasKey('performance', $afterReset);
    }

    /**
     * Tests reset resets uptime.
     */
    public function testResetResetsUptime(): void
    {
        // Arrange
        usleep(1000); // Wait 1ms
        $initialMetrics = $this->service->getMetrics();
        $initialUptime = $initialMetrics['uptime_seconds'];

        // Act
        $this->service->reset();
        $afterResetMetrics = $this->service->getMetrics();

        // Assert
        $this->assertLessThan($initialUptime, $afterResetMetrics['uptime_seconds']);
    }

    /**
     * Tests reset logs the reset operation.
     */
    public function testResetLogsResetOperation(): void
    {
        // Arrange
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Cache metrics have been reset.');

        // Act
        $this->service->reset();
    }

    /**
     * Tests operations work normally after reset.
     */
    public function testOperationsWorkAfterReset(): void
    {
        // Arrange
        $this->service->record('get', 0.001, true);
        $this->service->reset();

        // Act
        $this->service->record('set', 0.002, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(0, $metrics['counters']['get']); // Should be reset
        $this->assertSame(1, $metrics['counters']['set']); // Should work after reset
    }

    // ========================================================================
    // Edge Cases and Error Conditions Tests
    // ========================================================================

    /**
     * Tests recording with zero duration.
     */
    public function testRecordWithZeroDuration(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act
        $this->service->record('get', 0.0, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(1, $metrics['counters']['get']);

        if (isset($metrics['performance']['get'])) {
            $this->assertSame(0.0, $metrics['performance']['get']['avg_ms']);
        }
    }

    /**
     * Tests recording with negative duration (edge case).
     */
    public function testRecordWithNegativeDuration(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act
        $this->service->record('get', -0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(1, $metrics['counters']['get']);

        // Should handle negative duration gracefully
        if (isset($metrics['performance']['get'])) {
            $this->assertIsFloat($metrics['performance']['get']['avg_ms']);
        }
    }

    /**
     * Tests recording with very large duration.
     */
    public function testRecordWithLargeDuration(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act
        $this->service->record('get', 60.0, true); // 60 seconds

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(1, $metrics['counters']['get']);

        if (isset($metrics['performance']['get'])) {
            $this->assertSame(60000.0, $metrics['performance']['get']['avg_ms']);
        }
    }

    /**
     * Tests recording operations with empty string name.
     */
    public function testRecordWithEmptyOperationName(): void
    {
        // Act
        $this->service->record('', 0.001, true);

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertArrayHasKey('', $metrics['counters']);
        $this->assertSame(1, $metrics['counters']['']);
    }

    /**
     * Tests concurrent-like operations (multiple rapid records).
     */
    public function testMultipleRapidOperations(): void
    {
        // Act
        for ($i = 0; $i < 100; ++$i) {
            $this->service->record('get', 0.001, true);
        }

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(100, $metrics['counters']['get']);
        $this->assertSame(100, $metrics['total_operations']);
    }

    // ========================================================================
    // Comprehensive Workflow Tests
    // ========================================================================

    /**
     * Tests a realistic cache usage workflow.
     */
    public function testRealisticCacheWorkflow(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();

        // Act - Simulate realistic cache operations
        // Initial misses
        $this->service->record('get', 0.001, true);
        $this->service->record('misses', 0.001, true);

        // Cache population
        $this->service->record('set', 0.005, true);

        // Subsequent hits
        $this->service->record('get', 0.0005, true);
        $this->service->record('hits', 0.0005, true);
        $this->service->record('get', 0.0008, true);
        $this->service->record('hits', 0.0008, true);

        // Some updates
        $this->service->record('set', 0.003, true);
        $this->service->record('delete', 0.002, true);

        // Assert
        $metrics = $this->service->getMetrics();

        // Verify counters
        $this->assertSame(3, $metrics['counters']['get']); // 3 get operations
        $this->assertSame(2, $metrics['counters']['set']);
        $this->assertSame(1, $metrics['counters']['delete']);
        $this->assertSame(2, $metrics['counters']['hits']);
        $this->assertSame(1, $metrics['counters']['misses']);

        // Verify hit rate (2 hits out of 3 reads)
        $this->assertEqualsWithDelta(0.6667, $metrics['hit_rate'], 0.001);

        // Verify total operations (excludes hits, misses, errors)
        $this->assertSame(6, $metrics['total_operations']); // get(3) + set(2) + delete(1)

        // Verify performance metrics exist
        $this->assertArrayHasKey('performance', $metrics);
        $this->assertArrayHasKey('get', $metrics['performance']);
        $this->assertArrayHasKey('set', $metrics['performance']);
    }

    /**
     * Tests error rate calculation in mixed success/failure scenario.
     */
    public function testErrorRateCalculation(): void
    {
        // Act
        $this->service->record('get', 0.001, true);   // Success
        $this->service->record('get', 0.002, false);  // Failure
        $this->service->record('set', 0.003, true);   // Success
        $this->service->record('set', 0.004, false);  // Failure
        $this->service->record('set', 0.005, false);  // Failure

        // Assert
        $metrics = $this->service->getMetrics();
        $this->assertSame(2, $metrics['counters']['get']);
        $this->assertSame(3, $metrics['counters']['set']);
        $this->assertSame(3, $metrics['counters']['errors']); // 3 failures
        $this->assertSame(5, $metrics['total_operations']); // get(2) + set(3) = 5 (errors are not counted as operations)
    }

    // ========================================================================
    // Performance and Memory Tests
    // ========================================================================

    /**
     * Tests that metrics calculation is efficient with large datasets.
     */
    public function testPerformanceWithLargeDataset(): void
    {
        // Arrange
        $this->service->enableDetailedMetrics();
        $startTime = microtime(true);

        // Act
        for ($i = 0; $i < 1000; ++$i) {
            $this->service->record('get', 0.001 + ($i * 0.0001), true);
        }

        $metrics = $this->service->getMetrics();
        $endTime = microtime(true);

        // Assert
        $this->assertLessThan(1.0, $endTime - $startTime); // Should complete quickly
        $this->assertSame(1000, $metrics['counters']['get']);
        $this->assertArrayHasKey('performance', $metrics);
        $this->assertArrayHasKey('get', $metrics['performance']);
    }

    // ========================================================================
    // Data Providers
    // ========================================================================

    /**
     * Provides basic cache operations for testing.
     *
     * @return array<string, array<string>>
     */
    public static function basicOperationProvider(): array
    {
        return [
            'get operation' => ['get'],
            'set operation' => ['set'],
            'delete operation' => ['delete'],
            'clear operation' => ['clear'],
            'has operation' => ['has'],
            'getMultiple operation' => ['getMultiple'],
            'setMultiple operation' => ['setMultiple'],
            'deleteMultiple operation' => ['deleteMultiple'],
            'increment operation' => ['increment'],
            'decrement operation' => ['decrement'],
            'add operation' => ['add'],
            'hits operation' => ['hits'],
            'misses operation' => ['misses'],
        ];
    }

    // ========================================================================
    // Integration-style Tests
    // ========================================================================

    /**
     * Tests the service works correctly with different logger configurations.
     */
    public function testWorksWithDifferentLoggerConfigurations(): void
    {
        // Arrange
        $mockLogger = $this->createMock(Logger::class);

        // Act & Assert
        $service1 = new MetricsService();
        $service2 = new MetricsService($mockLogger);
        $service3 = new MetricsService(); // Default logger

        $this->assertInstanceOf(MetricsService::class, $service1);
        $this->assertInstanceOf(MetricsService::class, $service2);
        $this->assertInstanceOf(MetricsService::class, $service3);

        // Verify all work normally
        $service1->record('test', 0.001, true);
        $service2->record('test', 0.001, true);
        $service3->record('test', 0.001, true);

        $this->assertSame(1, $service1->getMetrics()['counters']['test']);
        $this->assertSame(1, $service2->getMetrics()['counters']['test']);
        $this->assertSame(1, $service3->getMetrics()['counters']['test']);
    }

    /**
     * Tests that multiple service instances are independent.
     */
    public function testMultipleInstancesAreIndependent(): void
    {
        // Arrange
        $service1 = new MetricsService();
        $service2 = new MetricsService();

        // Act
        $service1->record('get', 0.001, true);
        $service1->record('get', 0.002, true);
        $service2->record('set', 0.003, true);

        // Assert
        $metrics1 = $service1->getMetrics();
        $metrics2 = $service2->getMetrics();

        $this->assertSame(2, $metrics1['counters']['get']);
        $this->assertSame(0, $metrics1['counters']['set']);
        $this->assertSame(0, $metrics2['counters']['get']);
        $this->assertSame(1, $metrics2['counters']['set']);
    }

    public function testPerformanceSkipsEmptyDurationBuckets(): void
    {
        $service = new MetricsService();
        $service->enableDetailedMetrics();

        // Simulate an op that registers the counter but NO duration (edge-case)
        $reflection = new \ReflectionClass($service);
        $durations = $reflection->getProperty('durations');
        $durations->setAccessible(true);
        $durations->setValue($service, ['emptyOp' => []]);   // force empty bucket

        $metrics = $service->getMetrics();

        $this->assertArrayNotHasKey(
            'emptyOp',
            $metrics['performance'] ?? [],
            'Empty duration buckets must be ignored by calculatePerformanceMetrics()'
        );
    }

    public function testPercentileWithIntegerIndex(): void
    {
        $service = new MetricsService();
        $service->enableDetailedMetrics();

        // 3 equally-spaced samples → p50 should hit index 1 exactly
        $service->record('get', 0.001, true);
        $service->record('get', 0.002, true);
        $service->record('get', 0.003, true);

        $p50 = $service->getMetrics()['performance']['get']['p50_ms'];
        $this->assertSame(2.0, $p50, 'Median of three ordered samples should be the middle one (2 ms).');
    }

    public function testPercentileHandlesEmptyArray(): void
    {
        $service = new MetricsService();
        $service->enableDetailedMetrics();

        // Manually inject an empty array for a fake op
        $ref = new \ReflectionClass($service);
        $prop = $ref->getProperty('durations');
        $prop->setAccessible(true);
        $prop->setValue($service, ['emptyOp' => []]);

        $metrics = $service->getMetrics();

        // If the branch ran, it'll skip the op; nothing should explode
        $this->assertArrayNotHasKey('emptyOp', $metrics['performance'] ?? []);
    }

    public function testPercentileEdgeBranches(): void
    {
        $svc = new MetricsService();
        $ref = new \ReflectionClass($svc);
        $meth = $ref->getMethod('calculatePercentile');
        $meth->setAccessible(true);               // call the private helper

        // 1) empty-array branch ─ returns 0.0
        $this->assertSame(
            0.0,
            $meth->invoke($svc, [], 0.50)
        );

        // 2) exact-rank branch ─ returns the middle element
        $sorted = [10.0, 20.0, 30.0];             // n = 3 → p = 0.50 ⇒ index = 1.0
        $this->assertSame(
            20.0,
            $meth->invoke($svc, $sorted, 0.50)
        );
    }
}
