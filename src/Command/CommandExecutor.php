<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command;

use KaririCode\Cache\Contract\CacheCommand;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Executes any cache command that adheres to the CacheCommand interface.
 * This class is open for extension (by creating new commands) but
 * closed for modification, adhering to the Open/Closed Principle.
 */
final class CommandExecutor
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
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
