<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service\Event;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Service\Event\CacheEventLogger;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEventLogger::class)]
final class CacheEventLoggerTest extends TestCase
{
    private MockObject|LoggerRegistry $mockLoggerRegistry;
    private MockObject|Logger $mockCacheLogger;
    private MockObject|Logger $mockPerformanceLogger;
    private MockObject|Logger $mockErrorLogger;
    private MockObject|Logger $mockAsyncLogger;
    private MockObject|Logger $mockFallbackLogger;

    protected function setUp(): void
    {
        $this->mockCacheLogger = $this->createMock(Logger::class);
        $this->mockPerformanceLogger = $this->createMock(Logger::class);
        $this->mockErrorLogger = $this->createMock(Logger::class);
        $this->mockAsyncLogger = $this->createMock(Logger::class);
        $this->mockFallbackLogger = $this->createMock(Logger::class);
        $this->mockLoggerRegistry = $this->createMock(LoggerRegistry::class);

        $this->mockLoggerRegistry->method('getLogger')->willReturnMap([
            ['cache', $this->mockCacheLogger],
            ['performance', $this->mockPerformanceLogger],
            ['error', $this->mockErrorLogger],
            ['async', $this->mockAsyncLogger],
            ['system', $this->mockFallbackLogger],
        ]);
    }

    #[DataProvider('logOperationProvider')]
    public function testLogCacheOperationUsesCorrectLoggerAndLevel(string $operation, string $expectedLevel, string $expectedLogger, array $context): void
    {
        // Arrange
        $logger = new CacheEventLogger($this->mockLoggerRegistry, new EventServiceConfig());
        $mockLogger = $this->{'mock' . ucfirst($expectedLogger) . 'Logger'};
        $mockLogger->expects($this->once())->method($expectedLevel);

        // Act
        $logger->logCacheOperation($operation, 'Test Message', $context);
    }

    public function testLogPerformanceWarningWhenEnabled(): void
    {
        // Arrange
        $config = new EventServiceConfig(performanceLoggingEnabled: true);
        $logger = new CacheEventLogger($this->mockLoggerRegistry, $config);
        $this->mockPerformanceLogger->expects($this->once())->method('warning');

        // Act
        $logger->logPerformanceWarning('test_op', 0.5, 10.0, []);
    }

    public function testNoLogPerformanceWarningWhenDisabled(): void
    {
        // Arrange
        $config = new EventServiceConfig(performanceLoggingEnabled: false);
        $logger = new CacheEventLogger($this->mockLoggerRegistry, $config);
        $this->mockPerformanceLogger->expects($this->never())->method('warning');

        // Act
        $logger->logPerformanceWarning('test_op', 0.5, 10.0, []);
    }

    public function testLogErrorUsesFallbackOnErrorLoggerFailure(): void
    {
        // Arrange
        $logger = new CacheEventLogger($this->mockLoggerRegistry, new EventServiceConfig());
        $this->mockErrorLogger->method('error')->willThrowException(new \Exception('Primary logger failed'));
        $this->mockFallbackLogger->expects($this->once())->method('error');

        // Act
        $logger->logError('Test Error', []);
    }

    public function testExecuteLoggingWithFallbackCatchesErrors(): void
    {
        // Arrange
        $config = new EventServiceConfig();
        $logger = new CacheEventLogger($this->mockLoggerRegistry, $config);

        // Make the primary log operation fail
        $this->mockAsyncLogger->method('info')->willThrowException(new \RuntimeException('Primary log failed'));

        // Expect the error logger to be called as a fallback
        $this->mockErrorLogger->expects($this->once())->method('error')->with(
            'cache_miss logging failed',
            $this->callback(fn ($ctx) => 'Primary log failed' === $ctx['error'])
        );

        // Act
        $logger->logCacheOperation('cache_miss', 'Test Message', []);
    }

    public function testInitializeLoggersWithoutRegistryUsesFallback(): void
    {
        // Arrange & Act
        $logger = new CacheEventLogger(null, new EventServiceConfig());

        // Assert
        // This test's purpose is to cover the `if (null === $loggerRegistry)` branch.
        // We can verify by attempting a log operation, which should not throw an error.
        $logger->logCacheOperation('cache_hit', 'message', []);
        $this->assertTrue(true);
    }

    public function testLogErrorFailsSilentlyWhenAllLoggersFail(): void
    {
        // Arrange
        $logger = new CacheEventLogger($this->mockLoggerRegistry, new EventServiceConfig());
        $this->mockErrorLogger->method('error')->willThrowException(new \Exception('Primary logger failed'));
        $this->mockFallbackLogger->method('error')->willThrowException(new \Exception('Fallback logger failed'));

        // Act & Assert
        // Should not throw any exception
        $logger->logError('Test Error', []);
        $this->assertTrue(true);
    }

    #[DataProvider('severityProvider')]
    public function testCalculateSeverity(float $factor, string $expectedSeverity): void
    {
        // Arrange
        $logger = new CacheEventLogger($this->mockLoggerRegistry, new EventServiceConfig());
        $reflection = new \ReflectionClass($logger);
        $method = $reflection->getMethod('calculateSeverity');
        $method->setAccessible(true);
        $threshold = 10.0;
        $actualMs = $threshold * $factor;

        // Act
        $severity = $method->invoke($logger, $actualMs, $threshold);

        // Assert
        $this->assertSame($expectedSeverity, $severity);
    }

    public static function logOperationProvider(): array
    {
        return [
            'cache_hit' => ['cache_hit', 'debug', 'async', []],
            'cache_miss' => ['cache_miss', 'info', 'async', []],
            'cache_set' => ['cache_set', 'debug', 'async', []],
            'cache_delete_success' => ['cache_delete', 'debug', 'async', ['operation_success' => true]],
            'cache_delete_failure' => ['cache_delete', 'warning', 'cache', ['operation_success' => false]],
            'cache_clear' => ['cache_clear', 'info', 'cache', []],
            'tag_invalidation' => ['tag_invalidation', 'info', 'cache', []],
            'atomic_operation' => ['atomic_operation', 'debug', 'async', []],
            'unknown_operation' => ['unknown', 'info', 'cache', []],
        ];
    }

    public static function severityProvider(): array
    {
        return [
            'low' => [1.5, 'low'],
            'medium' => [2.5, 'medium'],
            'high' => [4.0, 'high'],
            'critical' => [6.0, 'critical'],
        ];
    }
}
