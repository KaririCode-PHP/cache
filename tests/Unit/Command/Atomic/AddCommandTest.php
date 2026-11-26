<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Atomic;

use KaririCode\Cache\Command\Atomic\AddCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AddCommand class.
 *
 * This test suite ensures that the AddCommand correctly performs atomic 'add' operations,
 * including validation, serialization, driver interaction, and conditional event dispatching
 * upon success or failure.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(AddCommand::class)]
final class AddCommandTest extends TestCase
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
     * Creates an instance of AddCommand with mocked dependencies.
     */
    private function createCommand(string $key, mixed $value): AddCommand
    {
        return new AddCommand(
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
     * Tests the successful execution path where the item is added to the cache.
     */
    public function testExecuteAddsItemAndDispatchesEventOnSuccess(): void
    {
        // Arrange
        $key = 'new_key';
        $value = 'new_value';
        $serializedValue = 'serialized_new_value';

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->validatorMock->expects($this->once())->method('validateValue')->with($value);
        $this->serializerMock->method('serialize')->with($value)->willReturn($serializedValue);
        $this->driverMock->method('add')->with($key, $serializedValue)->willReturn(true);

        $this->eventsMock->expects($this->once())->method('dispatchCacheSet');
        $this->metricsMock->expects($this->once())->method('record')->with('ADD', $this->isType('float'), true);

        $command = $this->createCommand($key, $value);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests the failure path where the item already exists in the cache.
     */
    public function testExecuteFailsAndDoesNotDispatchEventWhenItemExists(): void
    {
        // Arrange
        $key = 'existing_key';
        $value = 'some_value';
        $serializedValue = 'serialized_some_value';

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->validatorMock->expects($this->once())->method('validateValue')->with($value);
        $this->serializerMock->method('serialize')->with($value)->willReturn($serializedValue);
        $this->driverMock->method('add')->with($key, $serializedValue)->willReturn(false);

        $this->eventsMock->expects($this->never())->method('dispatchCacheSet');
        $this->metricsMock->expects($this->once())->method('record')->with('ADD', $this->isType('float'), false);

        $command = $this->createCommand($key, $value);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests that a configured TTL is correctly passed to the driver.
     */
    public function testExecuteWithTtlPassesExpirationToDriver(): void
    {
        // Arrange
        $key = 'key_with_ttl';
        $value = 'value_with_ttl';
        $ttl = 3600;

        $this->driverMock->expects($this->once())
            ->method('add')
            ->with(
                $this->equalTo($key),
                $this->anything(),
                $this->callback(function ($expiration) {
                    $this->assertInstanceOf(\DateTimeInterface::class, $expiration);
                    $this->assertEqualsWithDelta(time() + 3600, $expiration->getTimestamp(), 2);

                    return true;
                })
            )
            ->willReturn(true);

        $command = $this->createCommand($key, $value)->withTtl($ttl);

        // Act
        $command->execute();
    }

    /**
     * Tests that the getName method returns the correct command name.
     */
    public function testGetNameReturnsCorrectName(): void
    {
        // Arrange
        $command = $this->createCommand('any_key', 'any_value');

        // Act & Assert
        $this->assertSame('ADD', $command->getName());
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
        $this->assertArrayHasKey('command', $context);
        $this->assertArrayHasKey('key', $context);
        $this->assertArrayHasKey('value', $context);
        $this->assertSame('ADD', $context['command']);
        $this->assertSame('test_key', $context['key']);
        $this->assertSame('test_value', $context['value']);
    }
}
