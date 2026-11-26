<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Atomic;

use KaririCode\Cache\Command\Atomic\IncrementCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the IncrementCommand class.
 *
 * This test suite ensures that the IncrementCommand correctly performs atomic increments,
 * including parameter validation, driver interaction, and dispatching the
 * appropriate events and metrics.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(IncrementCommand::class)]
final class IncrementCommandTest extends TestCase
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
     * Creates an instance of IncrementCommand with mocked dependencies.
     */
    private function createCommand(string $key, int $step = 1, int $initialValue = 0): IncrementCommand
    {
        return new IncrementCommand(
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
     * Tests the successful execution of the increment command with default values.
     */
    public function testExecuteIncrementsValueAndDispatchesEvent(): void
    {
        // Arrange
        $key = 'counter';
        $newValue = 1;

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->driverMock->method('increment')->with($key, 1, 0)->willReturn($newValue);

        $this->eventsMock->expects($this->once())
            ->method('dispatchAtomicOperation')
            ->with('increment', $key, $newValue, new IsType(IsType::TYPE_FLOAT), ['step' => 1]);

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('INCREMENT', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($key);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertSame($newValue, $result);
    }

    /**
     * Tests the successful execution with custom step and initial values.
     */
    public function testExecuteWithCustomStepAndInitialValue(): void
    {
        // Arrange
        $key = 'custom_counter';
        $step = 5;
        $initialValue = 10;
        $newValue = 15; // initialValue + step

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->driverMock->method('increment')->with($key, $step, $initialValue)->willReturn($newValue);

        $this->eventsMock->expects($this->once())
            ->method('dispatchAtomicOperation')
            ->with('increment', $key, $newValue, new IsType(IsType::TYPE_FLOAT), ['step' => $step]);

        $command = $this->createCommand($key, $step, $initialValue);

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
        // The metrics should be recorded as a failure because validation fails
        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('INCREMENT', new IsType(IsType::TYPE_FLOAT), false);

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
        $this->assertSame('INCREMENT', $command->getName());
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $command = $this->createCommand('test_key', 5, 10);

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('key', $context);
        $this->assertArrayHasKey('step', $context);
        $this->assertArrayHasKey('initial_value', $context);
        $this->assertSame('test_key', $context['key']);
        $this->assertSame(5, $context['step']);
        $this->assertSame(10, $context['initial_value']);
    }
}
