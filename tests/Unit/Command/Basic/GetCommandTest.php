<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Basic;

use KaririCode\Cache\Command\Basic\GetCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the GetCommand class.
 *
 * This test suite validates all execution paths for the GetCommand, including
 * cache hits and misses. It ensures correct interaction with the driver, serializer,
 * and event/metrics services for each scenario.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(GetCommand::class)]
final class GetCommandTest extends TestCase
{
    private MockObject|LayeredDriverContract $driverMock;
    private MockObject|ValidationService $validatorMock;
    private MockObject|SerializationService $serializerMock;
    private MockObject|EventService $eventsMock;
    private MockObject|MetricsService $metricsMock;

    /**
     * Sets up the test environment by creating mocks for all dependencies.
     */
    protected function setUp(): void
    {
        $this->driverMock = $this->createMock(LayeredDriverContract::class);
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->serializerMock = $this->createMock(SerializationService::class);
        $this->eventsMock = $this->createMock(EventService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    /**
     * Creates an instance of GetCommand with mocked dependencies.
     */
    private function createCommand(string $key, mixed $default = null): GetCommand
    {
        return new GetCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->serializerMock,
            $this->eventsMock,
            $this->metricsMock,
            $key,
            $default
        );
    }

    /**
     * Tests the "cache hit" scenario, where the item is found and returned.
     */
    public function testExecuteReturnsValueAndDispatchesHitEventOnCacheHit(): void
    {
        // Arrange
        $key = 'test_key';
        $storedValue = 'serialized_value';
        $expectedValue = 'unserialized_value';
        $cacheItem = new CacheItem($key, $storedValue, true); // isHit = true

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->driverMock->method('getItem')->with($key)->willReturn($cacheItem);
        $this->serializerMock->method('unserialize')->with($storedValue)->willReturn($expectedValue);

        $this->eventsMock->expects($this->once())->method('dispatchCacheHit');
        $this->eventsMock->expects($this->never())->method('dispatchCacheMiss');
        $this->metricsMock->expects($this->once())->method('record')->with('GET', $this->isType('float'), true);

        $command = $this->createCommand($key);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertSame($expectedValue, $result);
    }

    /**
     * Tests the "cache miss" scenario, where the item is not found and the default value is returned.
     */
    public function testExecuteReturnsDefaultAndDispatchesMissEventOnCacheMiss(): void
    {
        // Arrange
        $key = 'miss_key';
        $default = 'default_value';
        $cacheItem = new CacheItem($key, null, false); // isHit = false

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->driverMock->method('getItem')->with($key)->willReturn($cacheItem);

        $this->serializerMock->expects($this->never())->method('unserialize');
        $this->eventsMock->expects($this->never())->method('dispatchCacheHit');
        $this->eventsMock->expects($this->once())->method('dispatchCacheMiss')->with($key);
        $this->metricsMock->expects($this->once())->method('record')->with('GET', $this->isType('float'), true);

        $command = $this->createCommand($key, $default);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertSame($default, $result);
    }

    /**
     * Tests that the getName method returns the correct command name.
     */
    public function testGetNameReturnsCorrectName(): void
    {
        // Arrange
        $command = $this->createCommand('any_key');

        // Act & Assert
        $this->assertSame('GET', $command->getName());
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $command = $this->createCommand('test_key');

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('command', $context);
        $this->assertArrayHasKey('key', $context);
        $this->assertSame('GET', $context['command']);
        $this->assertSame('test_key', $context['key']);
    }
}
