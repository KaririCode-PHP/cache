<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Basic;

use KaririCode\Cache\Command\Basic\ClearCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ClearCommand class.
 *
 * This test suite ensures that the ClearCommand correctly interacts with the driver
 * and dispatches the appropriate events upon success or failure, while also
 * recording metrics for every execution.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(ClearCommand::class)]
final class ClearCommandTest extends TestCase
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
     * Creates an instance of ClearCommand with mocked dependencies.
     */
    private function createCommand(): ClearCommand
    {
        return new ClearCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->eventsMock,
            $this->metricsMock
        );
    }

    /**
     * Tests that the command successfully clears the cache and dispatches an event.
     */
    public function testExecuteClearsCacheAndDispatchesEventOnSuccess(): void
    {
        // Arrange
        $this->driverMock->method('clear')->willReturn(true);

        $this->eventsMock->expects($this->once())
            ->method('dispatchCacheClear')
            ->with(new IsType(IsType::TYPE_FLOAT));

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('CLEAR', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand();

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests that the getContext method returns the expected basic context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $command = $this->createCommand();

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('command', $context);
        $this->assertSame('CLEAR', $context['command']);
        // Verify that no key is present, as it's not applicable
        $this->assertArrayNotHasKey('key', $context);
    }

    /**
     * Tests that the command handles a driver failure gracefully and does not dispatch an event.
     */
    public function testExecuteDoesNotDispatchEventOnFailure(): void
    {
        // Arrange
        $this->driverMock->method('clear')->willReturn(false);

        $this->eventsMock->expects($this->never())
            ->method('dispatchCacheClear');

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('CLEAR', new IsType(IsType::TYPE_FLOAT), false);

        $command = $this->createCommand();

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
        $command = $this->createCommand();

        // Act & Assert
        $this->assertSame('CLEAR', $command->getName());
    }

    /**
     * Tests that the validate method is correctly called but performs no action.
     */
    public function testValidateMethodIsCovered(): void
    {
        // Arrange
        $this->validatorMock->expects($this->never())->method($this->anything());
        $command = $this->createCommand();

        // Act
        $command->execute();

        // Assert
        // The test passes if no exception is thrown, confirming the empty validate() method is handled.
        $this->assertTrue(true);
    }
}
