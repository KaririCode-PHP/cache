<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command;

use KaririCode\Cache\Command\CommandExecutor;
use KaririCode\Cache\Contract\CacheCommand;
use KaririCode\Contract\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CommandExecutor class.
 *
 * This test suite ensures that the executor correctly handles both successful
 * command executions and exceptions, including proper logging and re-throwing
 * of errors, adhering to SOLID principles.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(CommandExecutor::class)]
final class CommandExecutorTest extends TestCase
{
    private MockObject|Logger $loggerMock;
    private CommandExecutor $executor;

    /**
     * Sets up the test environment before each test.
     */
    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(Logger::class);
        $this->executor = new CommandExecutor($this->loggerMock);
    }

    /**
     * Tests the successful execution path of a command.
     * It verifies that the command's result is returned and no error is logged.
     */
    public function testExecuteSuccessfullyReturnsCommandResult(): void
    {
        // Arrange
        $commandMock = $this->createMock(CacheCommand::class);
        $commandMock->method('execute')->willReturn('success_result');

        // Ensure the logger's error method is never called on success
        $this->loggerMock->expects($this->never())->method('error');

        // Act
        $result = $this->executor->execute($commandMock);

        // Assert
        $this->assertSame('success_result', $result);
    }

    /**
     * Tests the failure path when a command throws an exception.
     * It verifies that the exception is logged with the correct context and then re-thrown.
     */
    public function testExecuteLogsAndReThrowsExceptionOnFailure(): void
    {
        // Assert: Expect the original exception to be re-thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command failed!');

        // Arrange
        $exception = new \RuntimeException('Command failed!');
        $commandMock = $this->createMock(CacheCommand::class);
        $commandMock->method('execute')->willThrowException($exception);
        $commandMock->method('getName')->willReturn('TEST_COMMAND');
        $commandMock->method('getContext')->willReturn(['key' => 'test_key']);

        // Expect the logger to be called once with a specific message and context
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Command execution failed',
                $this->callback(function ($context) {
                    $this->assertArrayHasKey('command', $context);
                    $this->assertArrayHasKey('context', $context);
                    $this->assertArrayHasKey('error', $context);
                    $this->assertArrayHasKey('trace', $context);
                    $this->assertSame('TEST_COMMAND', $context['command']);
                    $this->assertSame(['key' => 'test_key'], $context['context']);
                    $this->assertSame('Command failed!', $context['error']);

                    return true;
                })
            );

        // Act
        $this->executor->execute($commandMock);
    }
}
