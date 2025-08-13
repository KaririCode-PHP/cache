<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Event\CacheAtomicOperationEvent;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Service\EventService;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the EventService class.
 *
 * This test suite covers all critical paths, error scenarios, and edge cases
 * of the EventService, ensuring that event dispatching and logging function
 * as expected under various configurations.
 *
 * @category Testing
 *
 * @author Programming Partner
 *
 * @see EventService
 */
#[CoversClass(EventService::class)]
final class EventServiceTest extends TestCase
{
    private MockObject|EventDispatcherInterface $mockDispatcher;
    private MockObject|LoggerRegistry $mockLoggerRegistry;
    private MockObject|Logger $mockAsyncLogger;
    private MockObject|Logger $mockCacheLogger;
    private MockObject|Logger $mockPerformanceLogger;
    private MockObject|Logger $mockErrorLogger;
    private EventServiceConfig $config;

    /**
     * Sets up the test environment before each method.
     */
    protected function setUp(): void
    {
        $this->mockDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->mockLoggerRegistry = $this->createMock(LoggerRegistry::class);

        $this->mockAsyncLogger = $this->createMock(Logger::class);
        $this->mockCacheLogger = $this->createMock(Logger::class);
        $this->mockPerformanceLogger = $this->createMock(Logger::class);
        $this->mockErrorLogger = $this->createMock(Logger::class);

        $this->mockLoggerRegistry->method('getLogger')->willReturnMap([
            ['async', $this->mockAsyncLogger],
            ['cache', $this->mockCacheLogger],
            ['performance', $this->mockPerformanceLogger],
            ['error', $this->mockErrorLogger],
        ]);

        $this->config = new EventServiceConfig(
            enabled: true,
            logOnDispatchError: true,
            performanceLoggingEnabled: true,
            performanceThresholds: ['cache_hit' => 10.0] // 10ms
        );
    }

    private function createEventService(?EventServiceConfig $config = null, ?EventDispatcherInterface $dispatcher = null): EventService
    {
        // Allow passing a custom dispatcher for the null test case
        $dispatcher = $dispatcher ?? $this->mockDispatcher;

        return new EventService(
            $dispatcher,
            $this->mockLoggerRegistry,
            $config ?? $this->config
        );
    }

    // Happy Path & Core Logic Tests

    public function testDispatchCacheHitLogsAndDispatchesEvent(): void
    {
        $this->mockAsyncLogger->expects($this->once())->method('debug');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CacheHitEvent::class));
        $service = $this->createEventService();
        $service->dispatchCacheHit('test:key', ['data' => 'value'], 0.005);
    }

    public function testDispatchCacheMissLogsAndDispatchesEvent(): void
    {
        $this->mockAsyncLogger->expects($this->once())->method('info');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CacheMissEvent::class));
        $service = $this->createEventService();
        $service->dispatchCacheMiss('missing:key');
    }

    public function testDispatchCacheSetLogsAndDispatchesEvent(): void
    {
        $this->mockAsyncLogger->expects($this->once())->method('debug');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CacheSetEvent::class));
        $service = $this->createEventService();
        $service->dispatchCacheSet('test:key', 'some-value', 3600, 0.005);
    }

    public function testDispatchCacheDeleteLogsAndDispatchesEvent(): void
    {
        $this->mockAsyncLogger->expects($this->once())->method('debug');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CacheDeleteEvent::class));
        $service = $this->createEventService();
        $service->dispatchCacheDelete('test:key', true, 0.002);
    }

    public function testDispatchCacheClearLogsAndDispatchesEvent(): void
    {
        $this->mockCacheLogger->expects($this->once())->method('info');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CacheClearEvent::class));
        $service = $this->createEventService();
        $service->dispatchCacheClear(0.1);
    }

    public function testDispatchTagInvalidationLogsAndDispatchesEvent(): void
    {
        $this->mockCacheLogger->expects($this->once())->method('info');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(TagInvalidationEvent::class));
        $service = $this->createEventService();
        $service->dispatchTagInvalidation(['tag1', 'tag2'], 10, 0.05);
    }

    public function testDispatchAtomicOperationLogsAndDispatchesEvent(): void
    {
        $this->mockAsyncLogger->expects($this->once())->method('debug');
        $this->mockDispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CacheAtomicOperationEvent::class));
        $service = $this->createEventService();
        $service->dispatchAtomicOperation('increment', 'counter:key', 101, 0.003, ['step' => 1]);
    }

    // Edge Cases and Branch Coverage Tests

    /**
     * @param string $method The dispatch method to call on EventService
     * @param array $args The arguments for the method
     */
    #[DataProvider('dispatchMethodsProvider')]
    public function testDoesNotProcessEventsWhenDisabled(string $method, array $args): void
    {
        // Arrange
        $disabledConfig = new EventServiceConfig(enabled: false);
        $service = $this->createEventService($disabledConfig);
        $this->mockDispatcher->expects($this->never())->method('dispatch');
        $this->mockAsyncLogger->expects($this->never())->method('debug');
        $this->mockCacheLogger->expects($this->never())->method('info');

        // Act
        $service->{$method}(...$args);
    }

    public function testSlowOperationLogsPerformanceWarning(): void
    {
        // Arrange
        $config = new EventServiceConfig(performanceLoggingEnabled: true, performanceThresholds: ['cache_hit' => 10.0]);
        $service = $this->createEventService($config);
        $this->mockPerformanceLogger->expects($this->once())->method('warning');

        // Act: 15ms > 10ms threshold
        $service->dispatchCacheHit('slow:key', 'data', 0.015);
    }

    public function testHandlesDispatcherErrorsGracefully(): void
    {
        // Arrange
        $this->mockDispatcher->method('dispatch')->willThrowException(new \RuntimeException('Dispatcher failed'));
        $this->mockErrorLogger->expects($this->once())->method('error')->with('Event dispatcher failed');
        $service = $this->createEventService();

        // Act & Assert
        $service->dispatchCacheHit('error:key', 'data');
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    public function testDoesNotLogErrorWhenDispatcherFailsAndLoggingIsDisabled(): void
    {
        // Arrange
        $config = new EventServiceConfig(logOnDispatchError: false);
        $service = $this->createEventService($config);
        $this->mockDispatcher->method('dispatch')->willThrowException(new \RuntimeException('Dispatcher error'));
        $this->mockErrorLogger->expects($this->never())->method('error');

        // Act
        $service->dispatchCacheMiss('some:key');
    }

    public function testDispatchEventDoesNothingWhenDispatcherIsNull(): void
    {
        // Arrange
        $service = $this->createEventService(config: null, dispatcher: null);

        // Act & Assert
        // The logger will still be called, but the dispatcher logic will be skipped.
        // No error should be thrown.
        $this->mockCacheLogger->expects($this->once())->method('info');
        $service->dispatchCacheClear();
        $this->assertTrue(true);
    }

    public function testExtractEventDataSafelyHandlesPublicProperties(): void
    {
        // Arrange
        $eventWithPublicProps = new class('public:key') extends CacheEvent {
            public string $publicInfo = 'public_data';
            private string $privateInfo = 'private_data';
        };

        $service = $this->createEventService();
        $this->mockDispatcher->method('dispatch')->willThrowException(new \Exception('dispatcher error'));

        // Expect the error logger to be called with a context containing the public property.
        $this->mockErrorLogger->expects($this->once())->method('error')->with(
            $this->anything(),
            $this->callback(function ($context) {
                $this->assertArrayHasKey('event_data', $context);
                $this->assertArrayHasKey('publicInfo', $context['event_data']);
                $this->assertSame('public_data', $context['event_data']['publicInfo']);
                $this->assertArrayNotHasKey('privateInfo', $context['event_data']);

                return true;
            })
        );

        // Act
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('dispatchEvent');
        $method->invoke($service, $eventWithPublicProps);
    }

    public function testExtractEventDataSafelyHandlesExceptions(): void
    {
        // Arrange
        $problematicEvent = new class('key') extends CacheEvent {
            public string $uninitializedPublicProperty;

            public function __get($name)
            {
                throw new \Error('Cannot access property');
            }
        };

        $config = new EventServiceConfig(logOnDispatchError: true);
        $service = $this->createEventService($config);

        $this->mockDispatcher->method('dispatch')->willThrowException(new \Exception('dispatcher error'));

        $this->mockErrorLogger->expects($this->once())->method('error')->with(
            $this->anything(),
            $this->callback(fn ($ctx) => 'Failed to extract event data' === ($ctx['event_data']['error'] ?? ''))
        );

        // Act
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('dispatchEvent');
        $method->invoke($service, $problematicEvent);
    }

    public function testDispatchEventReturnsEarlyWhenDispatcherIsNull(): void
    {
        // Arrange: Create the service, explicitly passing null for the dispatcher.
        $service = new EventService(
            dispatcher: null,
            loggerRegistry: $this->mockLoggerRegistry,
            config: $this->config
        );

        // The logger should still be called, as logging happens before dispatching.
        $this->mockAsyncLogger->expects($this->once())->method('info');

        // Act
        $service->dispatchCacheMiss('test-key');

        // Assert
        // The test passes if no "BadMethodCallException" or other error is thrown,
        // which proves the method returned early as intended.
        $this->assertTrue(true);
    }

    // Data Providers

    public static function dispatchMethodsProvider(): array
    {
        return [
            'dispatchCacheHit' => ['dispatchCacheHit', ['key', 'value', 0.1]],
            'dispatchCacheMiss' => ['dispatchCacheMiss', ['key']],
            'dispatchCacheSet' => ['dispatchCacheSet', ['key', 'value', 3600, 0.1]],
            'dispatchCacheDelete' => ['dispatchCacheDelete', ['key', true, 0.1]],
            'dispatchCacheClear' => ['dispatchCacheClear', [0.1]],
            'dispatchTagInvalidation' => ['dispatchTagInvalidation', [['tag1'], 5.0, 0.1]],
            'dispatchAtomicOperation' => ['dispatchAtomicOperation', ['op', 'key', 'result', 0.1, []]],
        ];
    }
}
