<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A concrete stub of AbstractCacheCommand for testing purposes.
 */
final class ConcreteTestCommand extends AbstractCacheCommand
{
    public bool $validateCalled = false;
    public bool $beforeExecuteCalled = false;
    public bool $doExecuteCalled = false;
    public bool $afterExecuteCalled = false;
    public bool $onErrorCalled = false;
    public ?\Throwable $exceptionToThrow = null;

    protected function validate(): void
    {
        $this->validateCalled = true;
    }

    protected function doExecute(): mixed
    {
        $this->doExecuteCalled = true;
        if ($this->exceptionToThrow) {
            throw $this->exceptionToThrow;
        }

        return 'success';
    }

    protected function beforeExecute(): void
    {
        $this->beforeExecuteCalled = true;
    }

    protected function afterExecute(mixed $result, float $duration): void
    {
        $this->afterExecuteCalled = true;
    }

    protected function onError(\Throwable $e): void
    {
        $this->onErrorCalled = true;
    }

    public function getName(): string
    {
        return 'TEST_COMMAND';
    }
}

/**
 * Unit tests for the AbstractCacheCommand class.
 *
 * This test suite uses a concrete stub to validate the template method pattern,
 * fluent interface immutability, and the overall execution lifecycle defined
 * in the abstract base class.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(AbstractCacheCommand::class)]
final class AbstractCacheCommandTest extends TestCase
{
    private MockObject|ValidationService $validatorMock;
    private MockObject|EventService $eventsMock;
    private MockObject|MetricsService $metricsMock;

    protected function setUp(): void
    {
        $this->validatorMock = $this->createMock(ValidationService::class);
        $this->eventsMock = $this->createMock(EventService::class);
        $this->metricsMock = $this->createMock(MetricsService::class);
    }

    private function createCommand(): ConcreteTestCommand
    {
        return new ConcreteTestCommand($this->validatorMock, $this->eventsMock, $this->metricsMock);
    }

    /**
     * Tests the successful execution lifecycle of the template method.
     */
    public function testExecuteFollowsCorrectLifecycleOnSuccess(): void
    {
        // Arrange
        $command = $this->createCommand();
        $this->metricsMock->expects($this->once())->method('record')->with('TEST_COMMAND', $this->isType('float'), true);

        // Act
        $result = $command->execute();

        // Assert
        $this->assertSame('success', $result);
        $this->assertTrue($command->validateCalled);
        $this->assertTrue($command->beforeExecuteCalled);
        $this->assertTrue($command->doExecuteCalled);
        $this->assertTrue($command->afterExecuteCalled);
        $this->assertFalse($command->onErrorCalled);
    }

    /**
     * Tests the execution lifecycle when an exception occurs.
     */
    public function testExecuteHandlesExceptionAndRecordsFailureMetric(): void
    {
        // Arrange
        $command = $this->createCommand();
        $command->exceptionToThrow = new \RuntimeException('Execution failed');

        $this->metricsMock->expects($this->once())->method('record')->with('TEST_COMMAND', $this->isType('float'), false);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Execution failed');

        try {
            // Act
            $command->execute();
        } finally {
            // Assert (in finally to ensure it runs after the exception)
            $this->assertTrue($command->validateCalled);
            $this->assertTrue($command->beforeExecuteCalled);
            $this->assertTrue($command->doExecuteCalled);
            $this->assertFalse($command->afterExecuteCalled, 'afterExecute should not be called on exception.');
            $this->assertTrue($command->onErrorCalled);
        }
    }

    /**
     * Tests that the withTtl method creates a new instance with the TTL set.
     */
    public function testWithTtlReturnsClonedInstance(): void
    {
        // Arrange
        $originalCommand = $this->createCommand();
        $ttl = 3600;

        // Act
        $newCommand = $originalCommand->withTtl($ttl);

        // Assert
        $this->assertNotSame($originalCommand, $newCommand);
        $reflection = new \ReflectionObject($newCommand);
        $ttlProperty = $reflection->getProperty('ttl');
        $this->assertSame($ttl, $ttlProperty->getValue($newCommand));
    }

    /**
     * Tests that the withTags method creates a new instance with tags set.
     */
    public function testWithTagsReturnsClonedInstance(): void
    {
        // Arrange
        $originalCommand = $this->createCommand();
        $tags = ['tag1', 'tag2'];

        // Act
        $newCommand = $originalCommand->withTags($tags);

        // Assert
        $this->assertNotSame($originalCommand, $newCommand);
        $reflection = new \ReflectionObject($newCommand);
        $tagsProperty = $reflection->getProperty('tags');
        $this->assertSame($tags, $tagsProperty->getValue($newCommand));
    }

    /**
     * Tests the normalizeTtl private method via the public withTtl interface.
     */
    public function testNormalizeTtlConvertsDateIntervalToSeconds(): void
    {
        // Arrange
        $command = $this->createCommand();
        $interval = new \DateInterval('PT1H30M'); // 1 hour 30 minutes

        // Act
        $newCommand = $command->withTtl($interval);

        // Assert
        $reflection = new \ReflectionObject($newCommand);
        $ttlProperty = $reflection->getProperty('ttl');
        $this->assertSame(5400, $ttlProperty->getValue($newCommand));
    }

    /**
     * Tests the getContext method for base context data.
     */
    public function testGetContextReturnsBaseContext(): void
    {
        // Arrange
        $command = $this->createCommand();

        $configuredCommand = $command->withTags(['tag1'])->withTtl(60);
        $configuredCommand->execute();

        // Act
        $context = $configuredCommand->getContext();

        // Assert
        $this->assertArrayHasKey('command', $context);
        $this->assertArrayHasKey('ttl', $context);
        $this->assertArrayHasKey('tags', $context);
        $this->assertArrayHasKey('executed_at', $context);
        $this->assertSame('TEST_COMMAND', $context['command']);
        $this->assertSame(60, $context['ttl']);
        $this->assertSame(['tag1'], $context['tags']);
    }
}
