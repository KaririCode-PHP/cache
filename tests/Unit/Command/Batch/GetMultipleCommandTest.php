<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Batch;

use KaririCode\Cache\Command\Batch\GetMultipleCommand;
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
 * Unit tests for the GetMultipleCommand class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(GetMultipleCommand::class)]
final class GetMultipleCommandTest extends TestCase
{
    private MockObject|LayeredDriverContract $driverMock;
    private MockObject|ValidationService $validatorMock;
    private MockObject|SerializationService $serializerMock;
    private MockObject|EventService $eventsMock;
    private MockObject|MetricsService $metricsMock;

    protected function setUp(): void
    {
        $this->driverMock = $this->createMock(LayeredDriverContract::class);
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->serializerMock = $this->createMock(SerializationService::class);
        $this->eventsMock = $this->createMock(EventService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    private function createCommand(iterable $keys): GetMultipleCommand
    {
        return new GetMultipleCommand($this->driverMock, $this->validatorMock, $this->serializerMock, $this->eventsMock, $this->metricsMock, $keys);
    }

    public function testExecuteHandlesMixOfHitsAndMisses(): void
    {
        // Arrange
        $keys = ['hit_key', 'miss_key'];
        $hitItem = new CacheItem('hit_key', 'serialized_value', true);
        $missItem = new CacheItem('miss_key', null, false);
        $driverResult = ['hit_key' => $hitItem, 'miss_key' => $missItem];

        $this->validatorMock->expects($this->once())->method('validateKeys')->with($keys);
        $this->driverMock->method('getItems')->with($keys)->willReturn($driverResult);
        $this->serializerMock->method('unserialize')->with('serialized_value')->willReturn('unserialized_value');

        $this->eventsMock->expects($this->once())->method('dispatchCacheHit')->with('hit_key', 'unserialized_value');
        $this->eventsMock->expects($this->once())->method('dispatchCacheMiss')->with('miss_key');
        $this->metricsMock->expects(
            $this->once()
        )->method('record')->with('GET_MULTIPLE', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($keys);

        // Act
        $result = $command->execute();

        // Assert
        $expectedResult = ['hit_key' => 'unserialized_value', 'miss_key' => null];
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $keys = ['key1', 'key2'];
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
        $this->assertSame('GET_MULTIPLE', $command->getName());
    }
}
