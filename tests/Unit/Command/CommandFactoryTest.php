<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Command;

use KaririCode\Cache\Command\Atomic\AddCommand;
use KaririCode\Cache\Command\Atomic\CompareAndSwapCommand;
use KaririCode\Cache\Command\Atomic\DecrementCommand;
use KaririCode\Cache\Command\Atomic\IncrementCommand;
use KaririCode\Cache\Command\Basic\ClearCommand;
use KaririCode\Cache\Command\Basic\DeleteCommand;
use KaririCode\Cache\Command\Basic\GetCommand;
use KaririCode\Cache\Command\Basic\HasCommand;
use KaririCode\Cache\Command\Basic\SetCommand;
use KaririCode\Cache\Command\Batch\DeleteMultipleCommand;
use KaririCode\Cache\Command\Batch\GetMultipleCommand;
use KaririCode\Cache\Command\Batch\SetMultipleCommand;
use KaririCode\Cache\Command\CommandFactory;
use KaririCode\Cache\Command\Tag\GetByTagsCommand;
use KaririCode\Cache\Command\Tag\InvalidateTagsCommand;
use KaririCode\Cache\Command\Tag\SetWithTagsCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CommandFactory class.
 *
 * This test suite ensures that the factory correctly instantiates every type
 * of command object, validating its core responsibility of object creation
 * and dependency injection according to SOLID principles.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(CommandFactory::class)]
final class CommandFactoryTest extends TestCase
{
    private CommandFactory $factory;

    /**
     * Sets up the test environment by creating a CommandFactory instance
     * with mocked dependencies.
     */
    protected function setUp(): void
    {
        $driverMock = $this->createMock(LayeredDriverContract::class);

        $this->factory = new CommandFactory(
            $driverMock,
            $this->createMock(ValidationService::class),
            $this->createMock(SerializationService::class),
            $this->createMock(EventService::class),
            $this->createMock(MetricsService::class)
        );
    }

    /**
     * Tests that the createGetCommand method returns a valid GetCommand instance.
     */
    public function testCreateGetCommand(): void
    {
        // Act
        $command = $this->factory->createGetCommand('key', 'default');
        // Assert
        $this->assertInstanceOf(GetCommand::class, $command);
    }

    /**
     * Tests that the createSetCommand method returns a valid SetCommand instance.
     */
    public function testCreateSetCommand(): void
    {
        // Act
        $command = $this->factory->createSetCommand('key', 'value');
        // Assert
        $this->assertInstanceOf(SetCommand::class, $command);
    }

    /**
     * Tests that the createDeleteCommand method returns a valid DeleteCommand instance.
     */
    public function testCreateDeleteCommand(): void
    {
        // Act
        $command = $this->factory->createDeleteCommand('key');
        // Assert
        $this->assertInstanceOf(DeleteCommand::class, $command);
    }

    /**
     * Tests that the createClearCommand method returns a valid ClearCommand instance.
     */
    public function testCreateClearCommand(): void
    {
        // Act
        $command = $this->factory->createClearCommand();
        // Assert
        $this->assertInstanceOf(ClearCommand::class, $command);
    }

    /**
     * Tests that the createHasCommand method returns a valid HasCommand instance.
     */
    public function testCreateHasCommand(): void
    {
        // Act
        $command = $this->factory->createHasCommand('key');
        // Assert
        $this->assertInstanceOf(HasCommand::class, $command);
    }

    /**
     * Tests that the createGetMultipleCommand method returns a valid GetMultipleCommand instance.
     */
    public function testCreateGetMultipleCommand(): void
    {
        // Act
        $command = $this->factory->createGetMultipleCommand(['key1', 'key2']);
        // Assert
        $this->assertInstanceOf(GetMultipleCommand::class, $command);
    }

    /**
     * Tests that the createSetMultipleCommand method returns a valid SetMultipleCommand instance.
     */
    public function testCreateSetMultipleCommand(): void
    {
        // Act
        $command = $this->factory->createSetMultipleCommand(['key' => 'value']);
        // Assert
        $this->assertInstanceOf(SetMultipleCommand::class, $command);
    }

    /**
     * Tests that the createDeleteMultipleCommand method returns a valid DeleteMultipleCommand instance.
     */
    public function testCreateDeleteMultipleCommand(): void
    {
        // Act
        $command = $this->factory->createDeleteMultipleCommand(['key1', 'key2']);
        // Assert
        $this->assertInstanceOf(DeleteMultipleCommand::class, $command);
    }

    /**
     * Tests that the createIncrementCommand method returns a valid IncrementCommand instance.
     */
    public function testCreateIncrementCommand(): void
    {
        // Act
        $command = $this->factory->createIncrementCommand('key', 1, 0);
        // Assert
        $this->assertInstanceOf(IncrementCommand::class, $command);
    }

    /**
     * Tests that the createDecrementCommand method returns a valid DecrementCommand instance.
     */
    public function testCreateDecrementCommand(): void
    {
        // Act
        $command = $this->factory->createDecrementCommand('key', 1, 0);
        // Assert
        $this->assertInstanceOf(DecrementCommand::class, $command);
    }

    /**
     * Tests that the createAddCommand method returns a valid AddCommand instance.
     */
    public function testCreateAddCommand(): void
    {
        // Act
        $command = $this->factory->createAddCommand('key', 'value');
        // Assert
        $this->assertInstanceOf(AddCommand::class, $command);
    }

    /**
     * Tests that the createCompareAndSwapCommand method returns a valid CompareAndSwapCommand instance.
     */
    public function testCreateCompareAndSwapCommand(): void
    {
        // Act
        $command = $this->factory->createCompareAndSwapCommand('key', 'old_value', 'new_value');
        // Assert
        $this->assertInstanceOf(CompareAndSwapCommand::class, $command);
    }

    /**
     * Tests that the createSetWithTagsCommand method returns a valid SetWithTagsCommand instance.
     */
    public function testCreateSetWithTagsCommand(): void
    {
        // Act
        $command = $this->factory->createSetWithTagsCommand('key', 'value', ['tag1']);
        // Assert
        $this->assertInstanceOf(SetWithTagsCommand::class, $command);
    }

    /**
     * Tests that the createInvalidateTagsCommand method returns a valid InvalidateTagsCommand instance.
     */
    public function testCreateInvalidateTagsCommand(): void
    {
        // Act
        $command = $this->factory->createInvalidateTagsCommand(['tag1']);
        // Assert
        $this->assertInstanceOf(InvalidateTagsCommand::class, $command);
    }

    /**
     * Tests that the createGetByTagsCommand method returns a valid GetByTagsCommand instance.
     */
    public function testCreateGetByTagsCommand(): void
    {
        // Act
        $command = $this->factory->createGetByTagsCommand(['tag1']);
        // Assert
        $this->assertInstanceOf(GetByTagsCommand::class, $command);
    }
}
