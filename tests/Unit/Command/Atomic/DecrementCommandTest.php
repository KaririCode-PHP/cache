<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Atomic;

use KaririCode\Cache\Command\Atomic\DecrementCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DecrementCommand class.
 *
 * This test suite ensures that the DecrementCommand correctly performs atomic decrements,
 * including parameter validation and driver interaction, while also recording
 * appropriate metrics for every execution.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(DecrementCommand::class)]
final class DecrementCommandTest extends TestCase
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
     * Creates an instance of DecrementCommand with mocked dependencies.
     */
    private function createCommand(string $key, int $step = 1, int $initialValue = 0): DecrementCommand
    {
        return new DecrementCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->eventsMock,
            $this->metricsMock,
            $key,
            $step,
            $initialValue
        );
    }

    /**
     * Tests the successful execution of the decrement command.
     */
    public function testExecuteDecrementsValueSuccessfully(): void
    {
        // Arrange
        $key = 'counter';
        $newValue = 99;

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->driverMock->method('decrement')->with($key, 1, 0)->willReturn($newValue);

        // This command does not have an afterExecute hook, so no event should be dispatched.
        $this->eventsMock->expects($this->never())->method('dispatchAtomicOperation');

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('DECREMENT', $this->isType('float'), true);

        $command = $this->createCommand($key);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertSame($newValue, $result);
    }

    /**
     * Tests that the validate method throws an exception for an invalid step value.
     *
     * @param int $invalidStep the invalid step value to test (zero or negative)
     */
    #[DataProvider('invalidStepProvider')]
    public function testValidateThrowsExceptionForInvalidStep(int $invalidStep): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid step value');

        // Arrange
        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('DECREMENT', $this->isType('float'), false);

        $command = $this->createCommand('any_key', $invalidStep);

        // Act
        $command->execute();
    }

    /**
     * Provides invalid step values for testing validation.
     *
     * @return array<string, array<int>>
     */
    public static function invalidStepProvider(): array
    {
        return [
            'zero step' => [0],
            'negative step' => [-5],
        ];
    }

    /**
     * Tests that the getName method returns the correct command name.
     */
    public function testGetNameReturnsCorrectName(): void
    {
        // Arrange
        $command = $this->createCommand('any_key');

        // Act & Assert
        $this->assertSame('DECREMENT', $command->getName());
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $command = $this->createCommand('test_key', 2, 50);

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('key', $context);
        $this->assertArrayHasKey('step', $context);
        $this->assertArrayHasKey('initial_value', $context);
        $this->assertSame('test_key', $context['key']);
        $this->assertSame(2, $context['step']);
        $this->assertSame(50, $context['initial_value']);
    }
}
