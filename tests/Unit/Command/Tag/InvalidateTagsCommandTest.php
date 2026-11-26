<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command\Tag;

use KaririCode\Cache\Command\Tag\InvalidateTagsCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the InvalidateTagsCommand class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(InvalidateTagsCommand::class)]
final class InvalidateTagsCommandTest extends TestCase
{
    private MockObject|LayeredDriverContract $driverMock;
    private MockObject|ValidationService $validatorMock;
    private MockObject|EventService $eventsMock;
    private MockObject|MetricsService $metricsMock;

    protected function setUp(): void
    {
        $this->driverMock = $this->createMock(LayeredDriverContract::class);
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->eventsMock = $this->createMock(EventService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    private function createCommand(array $tags): InvalidateTagsCommand
    {
        return new InvalidateTagsCommand(
            $this->driverMock,
            $this->validatorMock,
            $this->eventsMock,
            $this->metricsMock,
            $tags
        );
    }

    // Em: tests/Unit/Command/Tag/InvalidateTagsCommandTest.php

    public function testExecuteInvalidatesTagsAndDispatchesEvent(): void
    {
        // Arrange
        $tags = ['tag1', 'tag2'];

        $this->validatorMock->expects($this->once())->method('validateTags')->with($tags);

        $this->driverMock->method('invalidateTags')->with($tags)->willReturn(true);

        $this->eventsMock->expects($this->once())
            ->method('dispatchTagInvalidation')
            ->with($tags, -1, new IsType(IsType::TYPE_FLOAT));

        $this->metricsMock->expects($this->once())->method('record')->with('INVALIDATE_TAGS', new IsType(IsType::TYPE_FLOAT), true);

        $command = $this->createCommand($tags);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertTrue($result);
    }

    public function testExecuteWithEmptyTagsReturnsTrueWithoutAction(): void
    {
        // Arrange
        $command = $this->createCommand([]);
        $this->validatorMock->expects($this->once())->method('validateTags')->with([]);
        $this->driverMock->expects($this->never())->method('invalidateTags');
        $this->eventsMock->expects($this->never())->method('dispatchTagInvalidation');

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
        $this->assertSame('INVALIDATE_TAGS', $command->getName());
    }
}
