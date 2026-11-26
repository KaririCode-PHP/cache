<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface CacheCommand.
 *
 * Defines the contract for cache commands, which encapsulate operations
 * that can be executed on the cache. This interface allows for a consistent
 * way to define and execute cache-related commands, providing a clear
 * structure for command execution and metadata retrieval.
 * This interface is designed to be implemented by specific cache command classes,
 * which will define the actual logic for the command execution.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheCommand
{
    /**
     * Executes the cache command.
     *
     * This method should contain the logic for executing the command,
     * which may involve interacting with the cache to perform operations
     * such as setting, getting, or deleting cache entries.
     *
     * @return mixed the result of the command execution, which can vary based on the command type
     */
    public function execute(): mixed;

    /**
     * Gets the name of the cache command.
     *
     * This method should return a string that uniquely identifies the command,
     * which can be used for logging, debugging, or command identification purposes.
     *
     * @return string the name of the cache command
     */
    public function getName(): string;

    /**
     * Gets the context of the cache command.
     *
     * This method should return an associative array containing metadata about the command,
     * such as parameters, execution time, or any other relevant information that can help
     * in understanding the command's execution context.
     *
     * @return array an associative array representing the context of the cache command
     */
    public function getContext(): array;
}
