<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Batch;

use KaririCode\Cache\Command\Batch\DeleteMultipleCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DeleteMultipleCommand class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(DeleteMultipleCommand::class)]
final class DeleteMultipleCommandTest extends TestCase
{
    private MockObject|LayeredDriverContract $driverMock;
    private MockObject|ValidationService $validatorMock;
    private MockObject|MetricsService $metricsMock;

    protected function setUp(): void
    {
        $this->driverMock = $this->createMock(LayeredDriverContract::class);
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    private function createCommand(iterable $keys): DeleteMultipleCommand
    {
        return new DeleteMultipleCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->createMock(EventService::class), // Not used by this command
            $this->metricsMock,
            $keys
        );
    }

    public function testExecuteDeletesMultipleItemsSuccessfully(): void
    {
        // Arrange
        $keys = ['key1', 'key2'];
        $this->validatorMock->expects($this->once())->method('validateKeys')->with($keys);
        $this->driverMock->method('deleteItems')->with($keys)->willReturn(true);
        $this->metricsMock->expects(
            $this->once()
        )->method('record')->with('DELETE_MULTIPLE', new IsType(IsType::TYPE_FLOAT), true);
        $command = $this->createCommand($keys);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    public function testExecuteHandlesDeletionFailure(): void
    {
        // Arrange
        $keys = ['key1', 'key2'];
        $this->driverMock->method('deleteItems')->with($keys)->willReturn(false);
        $this->metricsMock->expects($this->once())->method('record')->with('DELETE_MULTIPLE', new IsType(IsType::TYPE_FLOAT), false);
        $command = $this->createCommand($keys);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $keys = ['key1', 'key2', 'key3'];
        $command = $this->createCommand($keys);

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('keys', $context);
        $this->assertEquals($keys, $context['keys']);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $command = $this->createCommand([]);
        $this->assertSame('DELETE_MULTIPLE', $command->getName());
    }
}
