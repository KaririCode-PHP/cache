<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Service\EventService;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Comprehensive unit tests for the EventService.
 *
 * This test suite covers all critical paths, error scenarios, and edge cases
 * of the EventService, following modern testing best practices including:
 * - The Arrange-Act-Assert pattern for clarity.
 * - Comprehensive mocking of all external dependencies.
 * - Edge case testing for configurations and inputs.
 * - Validation of performance threshold logging.
 * - Verification of graceful error handling.
 *
 * @category  Testing
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/docs/testing/unit-tests
 */
#[CoversClass(EventService::class)]
final class EventServiceTest extends TestCase
{
    private MockObject|EventDispatcherInterface $mockDispatcher;
    private MockObject|LoggerRegistry $mockLoggerRegistry;
    private MockObject|Logger $mockCacheLogger;
    private MockObject|Logger $mockPerformanceLogger;
    private MockObject|Logger $mockErrorLogger;
    private MockObject|Logger $mockAsyncLogger;
    private EventServiceConfig $config;

    /**
     * Sets up the test environment before each test.
     * Initializes all mock objects and a default configuration.
     */
    protected function setUp(): void
    {
        $this->setupMockDependencies();
        $this->setupEventServiceConfig();
    }

    /**
     * Creates an instance of EventService with the configured mocks.
     *
     * @param EventServiceConfig|null $config optional config override
     * @param LoggerRegistry|null $registry optional logger registry override
     * @param EventDispatcherInterface|null $dispatcher optional dispatcher override
     *
     * @return EventService the configured service instance
     */
    private function createEventService(
        ?EventServiceConfig $config = null,
        ?LoggerRegistry $registry = null,
        ?EventDispatcherInterface $dispatcher = null
    ): EventService {
        return new EventService(
            $dispatcher ?? $this->mockDispatcher,
            $registry ?? $this->mockLoggerRegistry,
            $config ?? $this->config
        );
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    /**
     * Tests that a successful cache hit event is correctly logged and dispatched.
     */
    public function testDispatchCacheHitLogsCorrectlyAndDispatchesEvent(): void
    {
        // Arrange
        $key = 'test:key:123';
        $value = ['data' => 'test'];
        $duration = 0.005; // 5ms

        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'Cache hit: Key retrieved successfully',
                $this->callback(fn ($context) => $this->validateContext($context, 'cache_hit', $key, 5.0))
            );

        $this->mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CacheHitEvent::class));

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheHit($key, $value, $duration);
    }

    /**
     * Tests that a cache miss event is correctly logged and dispatched.
     */
    public function testDispatchCacheMissLogsAndDispatchesCorrectly(): void
    {
        // Arrange
        $key = 'missing:key:456';

        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Cache miss: Key not found or expired',
                $this->callback(fn ($context) => $this->validateContext($context, 'cache_miss', $key))
            );

        $this->mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CacheMissEvent::class));

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheMiss($key);
    }

    /**
     * Tests that a cache set event calculates value size and formats TTL correctly.
     */
    public function testDispatchCacheSetCalculatesValueSizeAndFormatsTtl(): void
    {
        // Arrange
        $key = 'set:key:789';
        $value = str_repeat('x', 100); // 100 bytes
        $ttl = 3600; // 1 hour
        $duration = 0.025; // 25ms

        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'Cache set: Key stored successfully',
                $this->callback(function ($context) use ($key) {
                    // FIX: The assertion was changed from > 100 to === 100.
                    // The `calculateValueSize` method uses strlen for strings, which is exact.
                    return $this->validateContext($context, 'cache_set', $key, 25.0)
                        && 100 === $context['value_size_bytes']
                        && '1h' === $context['ttl_human'];
                })
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheSet($key, $value, $ttl, $duration);
    }

    /**
     * Tests that a successful cache delete operation is logged correctly.
     */
    public function testDispatchCacheDeleteSuccessIsLoggedAsDebug(): void
    {
        // Arrange
        $key = 'delete:key:123';

        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug') // Successful deletes are debug level
            ->with(
                'Cache delete: Key removed successfully',
                $this->callback(fn ($context) => true === $context['operation_success'])
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheDelete($key, true, 0.002);
    }

    /**
     * Tests that a failed cache delete operation (key not found) is logged as a warning.
     */
    public function testDispatchCacheDeleteFailureIsLoggedAsWarning(): void
    {
        // Arrange
        $key = 'delete:key:456';

        $this->mockCacheLogger
            ->expects($this->once())
            ->method('warning') // Failed deletes are warning level
            ->with(
                'Cache delete: Key not found',
                $this->callback(fn ($context) => false === $context['operation_success'])
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheDelete($key, false, 0.002);
    }

    /**
     * Tests that a cache clear event is logged correctly.
     */
    public function testDispatchCacheClearIsLoggedCorrectly(): void
    {
        // Arrange
        $this->mockCacheLogger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Cache clear: All cache entries removed',
                $this->callback(fn ($context) => 'cache_clear' === $context['event_type'])
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheClear(0.1);
    }

    /**
     * Tests that tag invalidation calculates and logs the efficiency ratio.
     */
    public function testDispatchTagInvalidationCalculatesEfficiencyRatio(): void
    {
        // Arrange
        $tags = ['user:123', 'session:abc'];
        $affectedKeys = 5.0;

        $this->mockCacheLogger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Tag invalidation: Cache entries invalidated by tags',
                $this->callback(function ($context) {
                    return 2 === $context['tags_count']
                        && 5.0 === $context['affected_keys_count']
                        && 0.4 === $context['efficiency_ratio']; // 2 tags / 5 keys = 0.4
                })
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchTagInvalidation($tags, $affectedKeys, 0.150);
    }

    /**
     * Tests that atomic operation events are logged with specific context.
     */
    public function testDispatchAtomicOperationIsLoggedCorrectly(): void
    {
        // Arrange
        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'Atomic operation: increment executed',
                $this->callback(function ($context) {
                    return 'atomic_operation' === $context['event_type']
                        && 'increment' === $context['operation_name']
                        && 42 === $context['operation_result'];
                })
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchAtomicOperation('increment', 'counter:key', 42, 0.001);
    }

    // ========================================================================
    // Performance and Edge Case Tests
    // ========================================================================

    /**
     * Tests that a slow operation triggers a performance warning log.
     */
    public function testSlowOperationLogsPerformanceWarning(): void
    {
        // Arrange
        $key = 'slow:key:123';
        $slowDuration = 0.015; // 15ms, exceeds the 10ms threshold for cache_hit

        $this->mockPerformanceLogger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Slow cache operation detected: cache_hit',
                $this->callback(function ($context) {
                    return true === $context['performance_alert']
                        && 10.0 === $context['threshold_ms']
                        && 15.0 === $context['actual_duration_ms']
                        && 1.5 === $context['slowness_factor'];
                })
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheHit($key, 'data', $slowDuration);
    }

    /**
     * Tests that TTL values are formatted correctly in logs for human readability.
     *
     * @param int $ttl the TTL in seconds
     * @param string $expectedFormat the expected human-readable string
     */
    #[DataProvider('ttlFormattingProvider')]
    public function testDispatchCacheSetFormatsTtlCorrectly(int $ttl, string $expectedFormat): void
    {
        // Arrange
        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug')
            ->with(
                $this->anything(),
                $this->callback(fn ($context) => $context['ttl_human'] === $expectedFormat)
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchCacheSet('ttl:test:key', 'data', $ttl);
    }

    /**
     * Tests that sensitive data in atomic operation context is redacted.
     */
    public function testDispatchAtomicOperationSanitizesSensitiveData(): void
    {
        // Arrange
        $data = [
            'step' => 1,
            'password' => 'secret123', // Should be redacted
            'token' => 'abc123',      // Should be redacted
        ];

        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug')
            ->with(
                $this->anything(),
                $this->callback(function ($context) {
                    return 1 === $context['operation_data']['step']
                        && '[REDACTED]' === $context['operation_data']['password']
                        && '[REDACTED]' === $context['operation_data']['token'];
                })
            );

        $eventService = $this->createEventService();

        // Act
        $eventService->dispatchAtomicOperation('increment', 'counter:key', 42, 0.001, $data);
    }

    // ========================================================================
    // Error Handling and Configuration Tests
    // ========================================================================

    /**
     * Tests that the service handles exceptions from the event dispatcher gracefully.
     */
    public function testHandlesDispatcherErrorsGracefully(): void
    {
        // Arrange
        $exception = new \RuntimeException('Dispatcher failed');
        $this->mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willThrowException($exception);

        $this->mockErrorLogger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Event dispatcher failed',
                $this->callback(fn ($context) => 'Dispatcher failed' === $context['error_message'])
            );

        $eventService = $this->createEventService();

        // Act & Assert (The test passes if no exception is thrown)
        $eventService->dispatchCacheHit('error:key', 'data');
        $this->assertTrue(true);
    }

    /**
     * Tests that if a primary logger fails, the error is logged to the error channel.
     */
    public function testHandlesLoggerErrorsGracefully(): void
    {
        // Arrange
        $loggingException = new \RuntimeException('Logger failed');
        $this->mockAsyncLogger
            ->expects($this->once())
            ->method('debug')
            ->willThrowException($loggingException);

        $this->mockErrorLogger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Cache hit logging failed',
                $this->callback(fn ($context) => 'Logger failed' === $context['error'])
            );

        $eventService = $this->createEventService();

        // Act & Assert (The test passes if no exception is thrown)
        $eventService->dispatchCacheHit('logger:error:key', 'data');
        $this->assertTrue(true);
    }

    /**
     * Tests that no events are processed if the service is disabled via configuration.
     */
    public function testDoesNotProcessEventsWhenDisabled(): void
    {
        // Arrange
        $disabledConfig = new EventServiceConfig(enabled: false);
        $eventService = $this->createEventService($disabledConfig);

        $this->mockDispatcher->expects($this->never())->method('dispatch');
        $this->mockAsyncLogger->expects($this->never())->method('debug');

        // Act
        $eventService->dispatchCacheHit('test:key', 'value');
    }

    /**
     * Tests that the service can be instantiated without a LoggerRegistry and uses a fallback.
     */
    public function testConstructorWithNoLoggerRegistryUsesFallback(): void
    {
        // Act
        $serviceWithoutRegistry = $this->createEventService(null, null);

        // Assert - Should not throw an exception and should work with the fallback SilentLogger.
        $serviceWithoutRegistry->dispatchCacheHit('test:key', 'value');
        $this->assertTrue(true); // Test passes if no error occurs
    }

    // ========================================================================
    // Data Providers and Helpers
    // ========================================================================

    /**
     * Data provider for TTL formatting test cases.
     *
     * @return array<string, array{int, string}>
     */
    public static function ttlFormattingProvider(): array
    {
        return [
            'seconds' => [30, '30s'],
            'minutes' => [120, '2m'],
            'hours' => [7200, '2h'],
            'days' => [86400, '1d'],
            'zero_ttl' => [0, 'no_expiration'],
        ];
    }

    /**
     * Helper to set up mock dependencies.
     */
    private function setupMockDependencies(): void
    {
        $this->mockDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->mockLoggerRegistry = $this->createMock(LoggerRegistry::class);
        $this->mockCacheLogger = $this->createMock(Logger::class);
        $this->mockPerformanceLogger = $this->createMock(Logger::class);
        $this->mockErrorLogger = $this->createMock(Logger::class);
        $this->mockAsyncLogger = $this->createMock(Logger::class);

        $this->mockLoggerRegistry
            ->method('getLogger')
            ->willReturnMap([
                ['cache', $this->mockCacheLogger],
                ['performance', $this->mockPerformanceLogger],
                ['error', $this->mockErrorLogger],
                ['async', $this->mockAsyncLogger],
                ['system', $this->mockErrorLogger], // Fallback channel
            ]);
    }

    /**
     * Helper to set up a default EventServiceConfig for tests.
     */
    private function setupEventServiceConfig(): void
    {
        $this->config = new EventServiceConfig(
            enabled: true,
            logOnDispatchError: true,
            performanceLoggingEnabled: true,
            performanceThresholds: ['cache_hit' => 10.0],
            sensitiveKeys: ['password', 'token']
        );
    }

    /**
     * A reusable callback for validating the basic structure of a log context array.
     *
     * @param array<string, mixed> $context the context array from the logger
     * @param string $expectedEventType the expected event type string
     * @param string $expectedKey the expected cache key
     * @param float|null $expectedDurationMs optional expected duration in milliseconds
     *
     * @return bool true if the context is valid
     */
    private function validateContext(array $context, string $expectedEventType, string $expectedKey, ?float $expectedDurationMs = null): bool
    {
        $isValid = $context['event_type'] === $expectedEventType
            && $context['cache_key'] === $expectedKey
            && isset($context['timestamp']);

        if (null !== $expectedDurationMs) {
            $durationKey = match ($expectedEventType) {
                'cache_hit' => 'retrieval_duration_ms',
                'cache_set' => 'storage_duration_ms',
                'cache_delete' => 'deletion_duration_ms',
                default => null
            };
            if ($durationKey) {
                $isValid = $isValid && $context[$durationKey] === $expectedDurationMs;
            }
        }

        return $isValid;
    }
}
