<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Tag;

use KaririCode\Cache\Command\Tag\SetWithTagsCommand;
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
 * Unit tests for the SetWithTagsCommand class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(SetWithTagsCommand::class)]
final class SetWithTagsCommandTest extends TestCase
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

    private function createCommand(string $key, mixed $value, array $tags): SetWithTagsCommand
    {
        return new SetWithTagsCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->serializerMock,
            $this->createMock(EventService::class), // Not used in this command's afterExecute
            $this->metricsMock,
            $key,
            $value,
            $tags
        );
    }

    public function testExecuteSavesItemWithTagsSuccessfully(): void
    {
        // Arrange
        $key = 'tagged_key';
        $value = 'tagged_value';
        $tags = ['tag1', 'tag2'];

        $this->validatorMock->expects($this->once())->method('validateKey')->with($key);
        $this->validatorMock->expects($this->once())->method('validateValue')->with($value);
        $this->validatorMock->expects($this->once())->method('validateTags')->with($tags);

        $this->driverMock->expects($this->once())
            ->method('save')
            ->with($this->callback(function (CacheItem $item) use ($key, $tags) {
                $this->assertSame($key, $item->getKey());
                $this->assertEquals($tags, $item->getTags());

                return true;
            }))
            ->willReturn(true);

        $this->metricsMock->expects(
            $this->once()
        )->method('record')->with('SET_WITH_TAGS', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($key, $value, $tags);

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
        $key = 'tagged_key';
        $value = 'tagged_value';
        $tags = ['tag1', 'tag2'];
        $command = $this->createCommand($key, $value, $tags);

        // Act
        $context = $command->getContext();

        // Assert
        $this->assertArrayHasKey('key', $context);
        $this->assertArrayHasKey('value', $context);
        $this->assertArrayHasKey('tags', $context);
        $this->assertSame($key, $context['key']);
        $this->assertSame($value, $context['value']);
        $this->assertSame($tags, $context['tags']);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $command = $this->createCommand('k', 'v', []);
        $this->assertSame('SET_WITH_TAGS', $command->getName());
    }
}
