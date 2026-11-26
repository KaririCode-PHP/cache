<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Basic;

use KaririCode\Cache\Command\Basic\HasCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the HasCommand class.
 *
 * This test suite ensures that the HasCommand correctly checks for the existence
 * of a cache key by interacting with the driver and properly records metrics
 * for both found and not-found scenarios.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(HasCommand::class)]
final class HasCommandTest extends TestCase
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
     * Creates an instance of HasCommand with mocked dependencies.
     */
    private function createCommand(string $key): HasCommand
    {
        return new HasCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->eventsMock,
            $this->metricsMock,
            $key
        );
    }

    /**
     * Tests the scenario where the item exists in the cache.
     */
    public function testExecuteReturnsTrueWhenItemExists(): void
    {
        // Arrange
        $key = 'existing_key';
        $this->driverMock->method('hasItem')->with($key)->willReturn(true);

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('HAS', new IsType(IsType::TYPE_FLOAT), true);

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
     * Tests the scenario where the item does not exist in the cache.
     */
    public function testExecuteReturnsFalseWhenItemDoesNotExist(): void
    {
        // Arrange
        $key = 'non_existing_key';
        $this->driverMock->method('hasItem')->with($key)->willReturn(false);

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('HAS', new IsType(IsType::TYPE_FLOAT), false);

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
        $this->assertSame('HAS', $command->getName());
    }
}
