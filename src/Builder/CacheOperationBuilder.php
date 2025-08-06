<?php

declare(strict_types=1);

namespace KaririCode\Cache\Builder;

use KaririCode\Cache\Command\CommandExecutor;
use KaririCode\Cache\Command\CommandFactory;
use KaririCode\Cache\Contract\CacheCommand;
use KaririCode\Cache\Contract\Command\SupportsCustomSerializer;
use KaririCode\Cache\Contract\Command\SupportsTags;
use KaririCode\Cache\Contract\Command\SupportsTopics;
use KaririCode\Cache\Contract\Command\SupportsTtl;

/**
 * Fluent builder for creating and executing cache operations.
 * Implements the Builder pattern for a flexible and readable API, allowing
 * method chaining to configure and run complex cache commands.
 */
final class CacheOperationBuilder
{
    private ?int $ttl = null;
    private array $tags = [];
    private array $topics = [];
    private ?string $serializerName = null;

    public function __construct(
        private readonly CommandFactory $commandFactory,
        private readonly CommandExecutor $executor
    ) {
    }

    // --- Configuration Methods ---

    /**
     * Set a Time-To-Live for the operation.
     */
    public function ttl(int|\DateInterval $ttl): self
    {
        return $this->cloneAndModify(function (self $clone) use ($ttl) {
            $clone->ttl = $ttl;
        });
    }

    /**
     * Add tags to the operation.
     */
    public function tags(string ...$tags): self
    {
        return $this->cloneAndModify(function (self $clone) use ($tags) {
            $clone->tags = array_unique(array_merge($clone->tags, $tags));
        });
    }

    /**
     * Add topics for pub/sub invalidation.
     */
    public function topics(string ...$topics): self
    {
        return $this->cloneAndModify(function (self $clone) use ($topics) {
            $clone->topics = array_unique(array_merge($clone->topics, $topics));
        });
    }

    /**
     * Specify a serializer to use for this single operation.
     */
    public function useSerializer(string $serializerName): self
    {
        return $this->cloneAndModify(function (self $clone) use ($serializerName) {
            $clone->serializerName = $serializerName;
        });
    }

    // --- Terminating Methods (Execution) ---

    public function get(string $key, mixed $default = null): mixed
    {
        $command = $this->commandFactory->createGetCommand($key, $default);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function set(string $key, mixed $value): bool
    {
        $command = $this->commandFactory->createSetCommand($key, $value);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function delete(string $key): bool
    {
        $command = $this->commandFactory->createDeleteCommand($key);
        $this->applyConfigurationToCommand($command); // TTL might be used by some drivers in future

        return $this->executor->execute($command);
    }

    public function has(string $key): bool
    {
        $command = $this->commandFactory->createHasCommand($key);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function clear(): bool
    {
        $command = $this->commandFactory->createClearCommand();
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function setMultiple(iterable $values): bool
    {
        $command = $this->commandFactory->createSetMultipleCommand($values);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $command = $this->commandFactory->createDeleteMultipleCommand($keys);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function add(string $key, mixed $value): bool
    {
        $command = $this->commandFactory->createAddCommand($key, $value);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $command = $this->commandFactory->createIncrementCommand($key, $step, $initialValue);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        $command = $this->commandFactory->createDecrementCommand($key, $step, $initialValue);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function invalidateTags(iterable $tags): bool
    {
        // O builder já tem tags, então podemos fundi-las
        $allTags = array_unique(array_merge($this->tags, is_array($tags) ? $tags : iterator_to_array($tags)));
        $command = $this->commandFactory->createInvalidateTagsCommand($allTags);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    public function getByTags(iterable $tags): iterable
    {
        $allTags = array_unique(array_merge($this->tags, is_array($tags) ? $tags : iterator_to_array($tags)));
        $command = $this->commandFactory->createGetByTagsCommand($allTags);
        $this->applyConfigurationToCommand($command);

        return $this->executor->execute($command);
    }

    // --- Helper Methods ---

    /**
     * Applies all fluent settings from the builder to a command object.
     */
    private function applyConfigurationToCommand(CacheCommand $command): void
    {
        if (null !== $this->ttl && $command instanceof SupportsTtl) {
            $command->withTtl($this->ttl);
        }
        if (!empty($this->tags) && $command instanceof SupportsTags) {
            $command->withTags($this->tags);
        }
        if (!empty($this->topics) && $command instanceof SupportsTopics) {
            $command->withTopics($this->topics);
        }
        if (null !== $this->serializerName && $command instanceof SupportsCustomSerializer) {
            $command->withSerializer($this->serializerName);
        }
    }

    /**
     * Clones the current builder instance and applies a modification.
     * This ensures the builder is immutable for safer chaining.
     *
     * @param callable(self): void $modifier
     */
    private function cloneAndModify(callable $modifier): self
    {
        $clone = clone $this;
        $modifier($clone);

        return $clone;
    }
}
