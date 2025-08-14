<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Batch;

use KaririCode\Cache\Command\Batch\SetMultipleCommand;
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
 * Unit tests for the SetMultipleCommand class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(SetMultipleCommand::class)]
final class SetMultipleCommandTest extends TestCase
{
    private MockObject|LayeredDriverContract $driverMock;
    private MockObject|ValidationService $validatorMock;
    private MockObject|SerializationService $serializerMock;
    private MockObject|MetricsService $metricsMock;

    protected function setUp(): void
    {
        $this->driverMock = $this->createMock(LayeredDriverContract::class);
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->serializerMock = $this->createMock(SerializationService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    private function createCommand(iterable $values): SetMultipleCommand
    {
        return new SetMultipleCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->serializerMock,
            $this->createMock(EventService::class), // Not used by this command
            $this->metricsMock,
            $values
        );
    }

    public function testExecuteSavesMultipleItemsSuccessfully(): void
    {
        // Arrange
        $values = ['key1' => 'val1', 'key2' => 'val2'];
        $this->validatorMock->expects($this->once())->method('validateMultipleValues')->with($values);
        $this->driverMock->expects($this->exactly(2))->method('saveDeferred')->willReturn(true);
        $this->driverMock->expects($this->once())->method('commit')->willReturn(true);
        $this->metricsMock->expects($this->once())->method('record')->with('SET_MULTIPLE', new IsType(IsType::TYPE_FLOAT), true);
        $command = $this->createCommand($values);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests that the command correctly handles a failure from saveDeferred.
     */
    public function testExecuteHandlesDeferredSaveFailure(): void
    {
        // Arrange
        $values = ['key1' => 'val1', 'key2' => 'val2'];
        // Use willReturnOnConsecutiveCalls for modern PHPUnit versions
        $this->driverMock->method('saveDeferred')->willReturnOnConsecutiveCalls(true, false); // Second save fails

        $this->driverMock->expects($this->never())->method('commit');

        $this->metricsMock->expects($this->once())
            ->method('record')
            ->with('SET_MULTIPLE', new IsType(IsType::TYPE_FLOAT), false);

        $command = $this->createCommand($values);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertFalse($result);
    }

    public function testExecuteHandlesCommitFailure(): void
    {
        // Arrange
        $values = ['key1' => 'val1', 'key2' => 'val2'];
        $this->driverMock->method('saveDeferred')->willReturn(true);
        $this->driverMock->method('commit')->willReturn(false); // Commit fails
        $this->metricsMock->expects(
            $this->once()
        )->method('record')->with('SET_MULTIPLE', new IsType(IsType::TYPE_FLOAT), false);
        $command = $this->createCommand($values);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertFalse($result);
    }

    public function testExecuteWithTtlAppliesToAllItems(): void
    {
        // Arrange
        $values = ['key1' => 'val1'];
        $ttl = 3600;
        $this->driverMock->expects($this->once())
            ->method('saveDeferred')
            ->with($this->callback(function (CacheItem $item) use ($ttl) {
                $this->assertNotNull($item->getExpiration());
                $this->assertEqualsWithDelta(time() + $ttl, $item->getExpiration()->getTimestamp(), 2);

                return true;
            }))
            ->willReturn(true);
        $this->driverMock->method('commit')->willReturn(true);

        $command = $this->createCommand($values)->withTtl($ttl);

        // Act
        $command->execute();
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $values = ['key1' => 'val1', 'key2' => 'val2'];
        $command = $this->createCommand($values);

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('keys', $context);
        $this->assertEquals(array_keys($values), $context['keys']);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $command = $this->createCommand([]);
        $this->assertSame('SET_MULTIPLE', $command->getName());
    }
}
