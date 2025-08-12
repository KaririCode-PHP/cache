<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command;

use KaririCode\Cache\Contract\CacheCommand;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\SilentLogger;

/**
 * Executes any cache command that adheres to the CacheCommand interface.
 * This class is open for extension (by creating new commands) but
 * closed for modification, adhering to the Open/Closed Principle.
 */
final class CommandExecutor
{
    public function __construct(private readonly Logger $logger = new SilentLogger())
    {
    }

    /**
     * Executes a generic cache command.
     *
     * @param CacheCommand $command the command to execute
     *
     * @throws \Throwable if the command execution fails
     *
     * @return mixed the result of the command's execution
     */
    public function execute(CacheCommand $command): mixed
    {
        try {
            // The template method in AbstractCacheCommand handles metrics, validation, etc.
            return $command->execute();
        } catch (\Throwable $e) {
            $this->logger->error('Command execution failed', [
                'command' => $command->getName(),
                'context' => $command->getContext(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Re-throw the exception to let the caller handle it.
            throw $e;
        }
    }
}
