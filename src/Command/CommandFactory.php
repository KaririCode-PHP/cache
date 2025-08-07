<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command;

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
use KaririCode\Cache\Command\Tag\GetByTagsCommand;
use KaririCode\Cache\Command\Tag\InvalidateTagsCommand;
use KaririCode\Cache\Command\Tag\SetWithTagsCommand;
use KaririCode\Cache\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Factory responsible for creating cache command instances.
 *
 * It centralizes the instantiation logic and dependency injection for all commands,
 * ensuring each command is created with the necessary driver and services.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class CommandFactory
{
    public function __construct(
        private readonly LayeredDriver $driver,
        private readonly ValidationService $validator,
        private readonly SerializationService $serializer,
        private readonly EventService $events,
        private readonly MetricsService $metrics
    ) {
    }

    // Basic Commands
    public function createGetCommand(string $key, mixed $default = null): GetCommand
    {
        return new GetCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $key,
            $default
        );
    }

    public function createSetCommand(string $key, mixed $value): SetCommand
    {
        return new SetCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $key,
            $value
        );
    }

    public function createDeleteCommand(string $key): DeleteCommand
    {
        return new DeleteCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics,
            $key
        );
    }

    public function createClearCommand(): ClearCommand
    {
        return new ClearCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics
        );
    }

    public function createHasCommand(string $key): HasCommand
    {
        return new HasCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics,
            $key
        );
    }

    // Batch Commands
    public function createGetMultipleCommand(iterable $keys): GetMultipleCommand
    {
        return new GetMultipleCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $keys
        );
    }

    public function createSetMultipleCommand(iterable $values): SetMultipleCommand
    {
        return new SetMultipleCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $values
        );
    }

    public function createDeleteMultipleCommand(iterable $keys): DeleteMultipleCommand
    {
        return new DeleteMultipleCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics,
            $keys
        );
    }

    // Atomic Commands
    public function createIncrementCommand(string $key, int $step = 1, int $initialValue = 0): IncrementCommand
    {
        return new IncrementCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics,
            $key,
            $step,
            $initialValue
        );
    }

    public function createDecrementCommand(string $key, int $step = 1, int $initialValue = 0): DecrementCommand
    {
        return new DecrementCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics,
            $key,
            $step,
            $initialValue
        );
    }

    public function createAddCommand(string $key, mixed $value): AddCommand
    {
        return new AddCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $key,
            $value
        );
    }

    public function createCompareAndSwapCommand(string $key, mixed $expectedValue, mixed $newValue): CompareAndSwapCommand
    {
        return new CompareAndSwapCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $key,
            $expectedValue,
            $newValue
        );
    }

    // Tag Commands
    public function createSetWithTagsCommand(
        string $key,
        mixed $value,
        iterable $tags
    ): SetWithTagsCommand {
        return new SetWithTagsCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $key,
            $value,
            $tags
        );
    }

    public function createInvalidateTagsCommand(iterable $tags): InvalidateTagsCommand
    {
        return new InvalidateTagsCommand(
            $this->driver,
            $this->validator,
            $this->events,
            $this->metrics,
            $tags
        );
    }

    public function createGetByTagsCommand(iterable $tags): GetByTagsCommand
    {
        return new GetByTagsCommand(
            $this->driver,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics,
            $tags
        );
    }
}
