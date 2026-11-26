<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Atomic;

use KaririCode\Cache\Command\Atomic\CompareAndSwapCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CompareAndSwapCommand class.
 *
 * This test suite ensures that the CompareAndSwapCommand correctly performs
 * atomic CAS operations, including validation of all inputs, serialization of
 * values, and correct interaction with the cache driver.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(CompareAndSwapCommand::class)]
final class CompareAndSwapCommandTest extends TestCase
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
     * Creates an instance of CompareAndSwapCommand with mocked dependencies.
     */
    private function createCommand(string $key, mixed $expected, mixed $new): CompareAndSwapCommand
    {
        return new CompareAndSwapCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->serializerMock,
            $this->eventsMock,
            $this->metricsMock,
            $key,
            $expected,
            $new
        );
    }

    /**
     * Tests the successful execution path where the expected value matches and the swap occurs.
     */
    public function testExecuteSucceedsWhenSwapOccurs(): void
    {
        // Arrange
        $key = 'cas_key';
        $expectedValue = 'old_value';
        $newValue = 'new_value';
        $serializedExpected = 'serialized_old';
        $serializedNew = 'serialized_new';

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);

        $expectedValidateValueCalls = [$expectedValue, $newValue];
        $this->validatorMock->expects($this->exactly(2))
            ->method('validateValue')
            ->willReturnCallback(function (mixed $value) use (&$expectedValidateValueCalls) {
                $expected = array_shift($expectedValidateValueCalls);
                $this->assertSame($expected, $value);
            });

        $this->serializerMock->method('serialize')->willReturnMap([
            [$expectedValue, null, $serializedExpected],
            [$newValue, null, $serializedNew],
        ]);

        $this->driverMock->method('compareAndSwap')
            ->with($key, $serializedExpected, $serializedNew, null)
            ->willReturn(true);

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('COMPARE_AND_SWAP', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($key, $expectedValue, $newValue);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests the failure path where the expected value does not match and the swap does not occur.
     */
    public function testExecuteFailsWhenSwapDoesNotOccur(): void
    {
        // Arrange
        $key = 'cas_key';
        $expectedValue = 'wrong_old_value';
        $newValue = 'new_value';

        $this->driverMock->method('compareAndSwap')->willReturn(false);

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('COMPARE_AND_SWAP', new IsType(IsType::TYPE_FLOAT), false);

        $command = $this->createCommand($key, $expectedValue, $newValue);

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
        $command = $this->createCommand('test_key', 'old_value', 'new_value');

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('command', $context);
        $this->assertArrayHasKey('key', $context);
        $this->assertArrayHasKey('expected_value', $context);
        $this->assertArrayHasKey('new_value', $context);
        $this->assertSame('COMPARE_AND_SWAP', $context['command']);
        $this->assertSame('test_key', $context['key']);
        $this->assertSame('old_value', $context['expected_value']);
        $this->assertSame('new_value', $context['new_value']);
    }

    /**
     * Tests that the getName method returns the correct command name.
     */
    public function testGetNameReturnsCorrectName(): void
    {
        // Arrange
        $command = $this->createCommand('any', 'old', 'new');

        // Act & Assert
        $this->assertSame('COMPARE_AND_SWAP', $command->getName());
    }
}
