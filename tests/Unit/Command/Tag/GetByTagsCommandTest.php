<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Tag;

use KaririCode\Cache\Command\Tag\GetByTagsCommand;
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
 * Unit tests for the GetByTagsCommand class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(GetByTagsCommand::class)]
final class GetByTagsCommandTest extends TestCase
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

    private function createCommand(array $tags): GetByTagsCommand
    {
        return new GetByTagsCommand($this->driverMock, $this->validatorMock, $this->serializerMock, $this->eventsMock, $this->metricsMock, $tags);
    }

    public function testExecuteReturnsAndDispatchesEventsForFoundItems(): void
    {
        // Arrange
        $tags = ['tag1'];
        $driverResult = ['key1' => 'val1_ser', 'key2' => 'val2_ser'];
        $expectedResult = ['key1' => 'val1_unser', 'key2' => 'val2_unser'];

        $this->validatorMock->expects($this->once())->method('validateTags')->with($tags);
        $this->driverMock->method('getByTags')->with($tags)->willReturn($driverResult);

        $this->serializerMock->method('unserialize')->willReturnMap([
            ['val1_ser', null, 'val1_unser'],
            ['val2_ser', null, 'val2_unser'],
        ]);

        $expectedDispatchCalls = [
            ['key1', 'val1_unser'],
            ['key2', 'val2_unser'],
        ];
        $this->eventsMock->expects($this->exactly(2))
            ->method('dispatchCacheHit')
            ->willReturnCallback(function (string $key, mixed $value) use (&$expectedDispatchCalls) {
                $expected = array_shift($expectedDispatchCalls);
                $this->assertSame($expected[0], $key);
                $this->assertSame($expected[1], $value);
            });

        $this->metricsMock->expects(
            $this->once()
        )->method('record')->with('GET_BY_TAGS', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($tags);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertEquals($expectedResult, $result);
    }

    public function testExecuteReturnsEmptyWhenNoItemsFound(): void
    {
        // Arrange
        $tags = ['tag_empty'];
        $this->driverMock->method('getByTags')->with($tags)->willReturn([]);
        $this->eventsMock->expects($this->never())->method('dispatchCacheHit');
        $command = $this->createCommand($tags);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertEmpty($result);
    }

    /**
     * Tests that the getContext method returns the expected context data.
     */
    public function testGetContextReturnsCorrectContext(): void
    {
        // Arrange
        $tags = ['tag1', 'tag2'];
        $command = $this->createCommand($tags);

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('tags', $context);
        $this->assertEquals($tags, $context['tags']);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $command = $this->createCommand([]);
        $this->assertSame('GET_BY_TAGS', $command->getName());
    }
}
