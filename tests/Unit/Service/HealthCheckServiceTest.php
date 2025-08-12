<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\DTO\HealthCheckResult;
use KaririCode\Cache\Service\HealthCheckService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Contract\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Complete unit tests for the HealthCheckService class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(HealthCheckService::class)]
final class HealthCheckServiceTest extends TestCase
{
    private LayeredDriver&MockObject $driverMock;
    private MetricsService&MockObject $metricsMock;
    private Logger&MockObject $loggerMock;
    private HealthCheckService $healthCheckService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driverMock = $this->createMock(LayeredDriver::class);
        // We remove 'final' from MetricsService to make it mockable
        $this->metricsMock = $this->createMock(MetricsService::class);
        $this->loggerMock = $this->createMock(Logger::class);

        $this->healthCheckService = new HealthCheckService(
            $this->driverMock,
            $this->metricsMock,
            $this->loggerMock
        );
    }

    public function testIsHealthyWhenAllChecksPass(): void
    {
        // Arrange
        $this->setupSuccessfulConnectivityCheck();
        $this->metricsMock->method('getMetrics')->willReturn(['hit_rate' => 0.9, 'total_operations' => 200]);

        // Act
        $isHealthy = $this->healthCheckService->isHealthy();

        // Assert
        $this->assertTrue($isHealthy);
    }

    public function testIsUnhealthyWhenConnectivityCheckFails(): void
    {
        // Arrange
        $item = new CacheItem('__health_check:connectivity', 'ok');
        $this->driverMock->method('getItem')->willReturn($item);
        $this->driverMock->method('save')->willReturn(false); // Make write fail
        $this->metricsMock->method('getMetrics')->willReturn(['hit_rate' => 0.9, 'total_operations' => 200]);

        // Act
        $isHealthy = $this->healthCheckService->isHealthy();

        // Assert
        $this->assertFalse($isHealthy);
    }

    public function testIsUnhealthyWhenHitRateIsLow(): void
    {
        // Arrange
        $this->setupSuccessfulConnectivityCheck();
        $this->metricsMock->method('getMetrics')->willReturn(['hit_rate' => 0.7, 'total_operations' => 200]); // Low hit rate

        // Act
        $isHealthy = $this->healthCheckService->isHealthy();

        // Assert
        $this->assertFalse($isHealthy);
    }

    public function testRunAllChecksReturnsProperStructure(): void
    {
        // Arrange
        $this->setupSuccessfulConnectivityCheck();
        $this->metricsMock->method('getMetrics')->willReturn(['hit_rate' => 0.9, 'total_operations' => 200]);

        // Act
        $results = $this->healthCheckService->runAllChecks();

        // Assert
        $this->assertIsArray($results);
        $this->assertArrayHasKey('connectivity', $results);
        $this->assertArrayHasKey('hit_rate', $results);

        foreach ($results as $result) {
            $this->assertInstanceOf(HealthCheckResult::class, $result);
        }
    }

    public function testGetDiagnosticsReturnsCompleteInformation(): void
    {
        // Arrange
        $this->setupSuccessfulConnectivityCheck();
        $expectedMetrics = ['hit_rate' => 0.95, 'total_operations' => 500];
        $this->metricsMock->method('getMetrics')->willReturn($expectedMetrics);

        // Act
        $diagnostics = $this->healthCheckService->getDiagnostics();

        // Assert
        $this->assertIsArray($diagnostics);
        $this->assertSame('HEALTHY', $diagnostics['health_status']);
        $this->assertArrayHasKey('checks', $diagnostics);
        $this->assertSame($expectedMetrics, $diagnostics['metrics']);
        $this->assertIsInt($diagnostics['timestamp']);
    }

    public function testHandlesDriverExceptionGracefully(): void
    {
        // Arrange
        $this->driverMock->method('getItem')->willThrowException(new \RuntimeException('Driver down'));
        $this->metricsMock->method('getMetrics')->willReturn([]);

        // Act
        $results = $this->healthCheckService->runAllChecks();

        // Assert
        $this->assertFalse($results['connectivity']->isHealthy);
        $this->assertStringContainsString('Driver down', $results['connectivity']->message);
    }

    #[DataProvider('metricsScenarioProvider')]
    public function testHitRateCheckWithDifferentMetrics(array $metrics, bool $expectedHealthy, string $expectedMessage): void
    {
        // Arrange
        $this->setupSuccessfulConnectivityCheck();
        $this->metricsMock->method('getMetrics')->willReturn($metrics);

        // Act
        $results = $this->healthCheckService->runAllChecks();

        // Assert
        $performanceResult = $results['hit_rate'];
        $this->assertSame($expectedHealthy, $performanceResult->isHealthy);
        $this->assertStringContainsString($expectedMessage, $performanceResult->message);
    }

    public static function metricsScenarioProvider(): array
    {
        return [
            'high hit rate' => [['hit_rate' => 0.9, 'total_operations' => 200], true, 'Hit rate is 90.00%'],
            'low hit rate' => [['hit_rate' => 0.7, 'total_operations' => 200], false, 'Low hit rate: 70.00%'],
            'good hit rate but few ops' => [['hit_rate' => 0.5, 'total_operations' => 50], true, 'Hit rate is 50.00%'],
            'no operations' => [['hit_rate' => 0.0, 'total_operations' => 0], true, 'Hit rate is 0.00%'],
            'missing metrics' => [[], true, 'Hit rate is 0.00%'],
        ];
    }

    private function setupSuccessfulConnectivityCheck(): void
    {
        // Correction: Instantiate a real CacheItem instead of mocking it.
        $item = new CacheItem('__health_check:connectivity', null, false);

        $this->driverMock->method('getItem')->willReturn($item);
        $this->driverMock->method('save')->willReturn(true);
        $this->driverMock->method('hasItem')->willReturn(true);
        $this->driverMock->method('deleteItem')->willReturn(true);
    }
}
