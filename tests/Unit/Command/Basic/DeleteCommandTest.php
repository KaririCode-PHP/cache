<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Basic;

use KaririCode\Cache\Command\Basic\DeleteCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DeleteCommand class.
 *
 * This test suite ensures that the DeleteCommand correctly interacts with the driver
 * to delete an item and always dispatches a completion event, regardless of
 * whether the item existed or not.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(DeleteCommand::class)]
final class DeleteCommandTest extends TestCase
{
    private MockObject|LayeredDriverContract $driverMock;
    private MockObject|ValidationService $validatorMock;
    private MockObject|EventService $eventsMock;
    private MockObject|MetricsService $metricsMock;

    /**
     * Sets up the test environment by creating mocks for all dependencies.
     */
    protected function setUp(): void
    {
        $this->driverMock = $this->createMock(LayeredDriverContract::class);
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->eventsMock = $this->createMock(EventService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    /**
     * Creates an instance of DeleteCommand with mocked dependencies.
     */
    private function createCommand(string $key): DeleteCommand
    {
        return new DeleteCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->eventsMock,
            $this->metricsMock,
            $key
        );
    }

    /**
     * Tests the successful execution path where the item is found and deleted.
     */
    public function testExecuteDeletesItemAndDispatchesEventOnSuccess(): void
    {
        // Arrange
        $key = 'existing_key';
        $this->driverMock->method('deleteItem')->with($key)->willReturn(true);

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->eventsMock->expects($this->once())
            ->method('dispatchCacheDelete')
            ->with($key, true, new IsType(IsType::TYPE_FLOAT)); // Expect success=true
        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('DELETE', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($key);

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
        $command = $this->createCommand('test_key');

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('key', $context);
        $this->assertSame('test_key', $context['key']);
    }

    /**
     * Tests the failure path where the item does not exist or deletion fails.
     */
    public function testExecuteStillDispatchesEventOnFailure(): void
    {
        // Arrange
        $key = 'non_existing_key';
        $this->driverMock->method('deleteItem')->with($key)->willReturn(false);

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->eventsMock->expects($this->once())
            ->method('dispatchCacheDelete')
            ->with($key, false, new IsType(IsType::TYPE_FLOAT)); // Expect success=false
        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('DELETE', new IsType(IsType::TYPE_FLOAT), false);

        $command = $this->createCommand($key);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests that the getName method returns the correct command name.
     */
    public function testGetNameReturnsCorrectName(): void
    {
        // Arrange
        $command = $this->createCommand('any_key');

        // Act & Assert
        $this->assertSame('DELETE', $command->getName());
    }
}
