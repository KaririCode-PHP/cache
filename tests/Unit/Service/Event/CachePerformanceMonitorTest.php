<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service\Event;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Service\Event\CachePerformanceMonitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachePerformanceMonitor::class)]
final class CachePerformanceMonitorTest extends TestCase
{
    public function testCheckPerformanceReturnsDataForSlowOperation(): void
    {
        // Arrange
        $config = new EventServiceConfig(performanceThresholds: ['test_op' => 50.0]);
        $monitor = new CachePerformanceMonitor($config);

        // Act
        $result = $monitor->checkPerformance(0.1, 'test_op', 'key'); // 100ms > 50ms

        // Assert
        $this->assertIsArray($result);
        $this->assertSame('test_op', $result['operation']);
        $this->assertEquals(100.0, $result['duration_ms']);
    }

    public function testCheckPerformanceReturnsNullForFastOperation(): void
    {
        // Arrange
        $config = new EventServiceConfig(performanceThresholds: ['test_op' => 50.0]);
        $monitor = new CachePerformanceMonitor($config);

        // Act
        $result = $monitor->checkPerformance(0.01, 'test_op', 'key'); // 10ms < 50ms

        // Assert
        $this->assertNull($result);
    }

    public function testCheckPerformanceReturnsNullWhenDisabled(): void
    {
        // Arrange
        $config = new EventServiceConfig(performanceLoggingEnabled: false);
        $monitor = new CachePerformanceMonitor($config);

        // Act
        $result = $monitor->checkPerformance(0.1, 'test_op', 'key'); // 100ms

        // Assert
        $this->assertNull($result);
    }
}
