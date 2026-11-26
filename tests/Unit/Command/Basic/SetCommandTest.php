<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Basic;

use KaririCode\Cache\Command\Basic\SetCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SetCommand class.
 *
 * This test suite ensures that the SetCommand correctly handles the process of
 * setting a cache item, including validation, serialization, driver interaction,
 * TTL handling, and conditional event dispatching.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(SetCommand::class)]
final class SetCommandTest extends TestCase
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
     * Creates an instance of SetCommand with mocked dependencies.
     */
    private function createCommand(string $key, mixed $value): SetCommand
    {
        return new SetCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->serializerMock,
            $this->eventsMock,
            $this->metricsMock,
            $key,
            $value
        );
    }

    /**
     * Tests the successful execution path where the item is saved and an event is dispatched.
     */
    public function testExecuteSavesItemAndDispatchesEventOnSuccess(): void
    {
        // Arrange
        $key = 'test_key';
        $value = 'test_value';
        $serializedValue = 'serialized_value';

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->validatorMock->expects($this->once())->method('validateValue')->with($value);
        $this->serializerMock->method('serialize')->with($value)->willReturn($serializedValue);
        $this->driverMock->method('save')->willReturn(true);

        $this->eventsMock->expects($this->once())->method('dispatchCacheSet');
        $this->metricsMock->expects(
            $this->once()
        )->method('record')->with('SET', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($key, $value);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $command = $this->createCommand('test_key', 'test_value');

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('key', $context);
        $this->assertArrayHasKey('value', $context);
        $this->assertSame('test_key', $context['key']);
        $this->assertSame('test_value', $context['value']);
    }

    /**
     * Tests the failure path where the driver fails to save the item.
     */
    public function testExecuteDoesNotDispatchEventOnFailure(): void
    {
        // Arrange
        $key = 'test_key';
        $value = 'test_value';

        $this->driverMock->method('save')->willReturn(false);
        $this->eventsMock->expects($this->never())->method('dispatchCacheSet');
        $this->metricsMock->expects($this->once())->method('record')->with('SET', new IsType(IsType::TYPE_FLOAT), false);

        $command = $this->createCommand($key, $value);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests that a configured TTL is correctly applied to the CacheItem passed to the driver.
     */
    public function testExecuteWithTtlSetsExpirationOnCacheItem(): void
    {
        // Arrange
        $key = 'key_with_ttl';
        $value = 'value_with_ttl';
        $ttl = 3600;

        $this->driverMock->expects($this->once())
            ->method('save')
            ->with($this->callback(function (CacheItem $item) use ($key, $ttl) {
                $this->assertSame($key, $item->getKey());
                $this->assertNotNull($item->getExpiration());
                $this->assertEqualsWithDelta(time() + $ttl, $item->getExpiration()->getTimestamp(), 2);

                return true;
            }))
            ->willReturn(true);

        $command = $this->createCommand($key, $value)->withTtl($ttl);

        // Act
        $command->execute();
    }

    /**
     * Tests that the fluent withTtl method creates a new, modified instance, ensuring immutability.
     */
    public function testWithTtlReturnsClonedInstanceWithTtlSet(): void
    {
        // Arrange
        $originalCommand = $this->createCommand('key', 'value');

        // Act
        $newCommand = $originalCommand->withTtl(3600);

        // Assert
        $this->assertNotSame($originalCommand, $newCommand, 'withTtl must return a new instance.');
        $this->assertInstanceOf(SetCommand::class, $newCommand);

        // Use reflection to check the protected property on the new instance
        $reflection = new \ReflectionObject($newCommand);
        $ttlProperty = $reflection->getProperty('ttl');
        $this->assertSame(3600, $ttlProperty->getValue($newCommand));
    }

    /**
     * Tests that the getName method returns the correct command name.
     */
    public function testGetNameReturnsCorrectName(): void
    {
        // Arrange
        $command = $this->createCommand('any_key', 'any_value');

        // Act & Assert
        $this->assertSame('SET', $command->getName());
    }
}
