<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Service\EventService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the EventService class.
 *
 * This test suite validates the core responsibilities of the EventService, ensuring
 * that it correctly logs events, dispatches them when a dispatcher is present,
 * and handles configurations and errors gracefully, adhering to SOLID principles.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(EventService::class)]
final class EventServiceTest extends TestCase
{
    private EventDispatcherInterface&MockObject $dispatcherMock;
    private LoggerInterface&MockObject $loggerMock;

    /**
     * Sets up the mocks for the event dispatcher and logger before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    /**
     * Verifies that events are both logged and dispatched when the service is enabled
     * with a valid dispatcher.
     */
    public function testEventsAreDispatchedAndLoggedWhenEnabled(): void
    {
        // Arrange
        $service = new EventService($this->dispatcherMock, $this->loggerMock);

        // Assert
        $this->loggerMock
            ->expects($this->once())
            ->method('log');

        $this->dispatcherMock
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CacheHitEvent::class));

        // Act
        $service->dispatchCacheHit('test', 'value');
    }

    /**
     * Verifies that no logging or dispatching occurs when the service is explicitly disabled
     * via its configuration DTO.
     */
    public function testEventsAreNotDispatchedOrLoggedWhenDisabled(): void
    {
        // Arrange
        $config = new EventServiceConfig(enabled: false);
        $service = new EventService($this->dispatcherMock, $this->loggerMock, $config);

        // Assert
        $this->loggerMock->expects($this->never())->method('log');
        $this->dispatcherMock->expects($this->never())->method('dispatch');

        // Act
        $service->dispatchCacheHit('test', 'value');
    }

    /**
     * Verifies that the service still logs an event even when no event dispatcher is provided,
     * confirming the decoupling of logging and dispatching logic.
     */
    public function testLogsButDoesNotDispatchWhenDispatcherIsNull(): void
    {
        // Arrange
        $service = new EventService(null, $this->loggerMock);

        // Assert
        $this->loggerMock->expects($this->once())->method('log');
        $this->dispatcherMock->expects($this->never())->method('dispatch');

        // Act
        $service->dispatchCacheHit('test', 'value');
    }

    /**
     * Verifies that an exception thrown by the logger is caught gracefully, allowing the
     * event dispatching to proceed without crashing the application.
     */
    public function testHandlesLoggerExceptionsGracefully(): void
    {
        // Arrange
        $this->loggerMock->method('log')->willThrowException(new \RuntimeException('Logger error'));
        $service = new EventService($this->dispatcherMock, $this->loggerMock);

        // Assert
        $this->dispatcherMock->expects($this->once())->method('dispatch');

        // Act
        $service->dispatchCacheHit('test', 'value');

        $this->assertTrue(true, 'Test completed without crashing, meaning exception was handled.');
    }

    /**
     * Verifies that an exception from the event dispatcher is caught and logged,
     * preventing the application from crashing.
     */
    public function testHandlesDispatcherExceptionsGracefullyAndLogsError(): void
    {
        // Arrange
        $this->dispatcherMock->method('dispatch')->willThrowException(new \RuntimeException('Dispatcher error'));
        $service = new EventService($this->dispatcherMock, $this->loggerMock);

        // Assert: Expect two calls to the logger, verifying the arguments of each call.
        $callIndex = 0;
        $this->loggerMock->expects($this->exactly(2))->method('log')
            ->willReturnCallback(function (string $level, string $message, array $context) use (&$callIndex) {
                if (0 === $callIndex) {
                    $this->assertSame('debug', $level);
                    $this->assertSame('Cache hit', $message);
                    $this->assertSame(['key' => 'test'], $context);
                }
                if (1 === $callIndex) {
                    $this->assertSame('error', $level);
                    $this->assertSame('Event dispatcher failed.', $message);
                    $this->assertArrayHasKey('error', $context);
                }
                ++$callIndex;
            });

        // Act
        $service->dispatchCacheHit('test', 'value');

        $this->assertTrue(true, 'Test completed without crashing, meaning exception was handled.');
    }
}
