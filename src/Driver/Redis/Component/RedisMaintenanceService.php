<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Component;

use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Contract\Logging\Logger;

/**
 * Handles maintenance operations for the Redis driver.
 *
 * This class is solely responsible for performing maintenance tasks such as
 * cleaning up expired keys (emulated), triggering memory defragmentation,
 * and running memory analysis. It adheres to the Single Responsibility Principle.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class RedisMaintenanceService
{
    /**
     * @param \Redis $redis the Redis client instance
     * @param RedisConfig $config configuration settings
     * @param Logger $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly RedisConfig $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Performs maintenance tasks on the Redis cache.
     *
     * @param array<string, mixed> $options driver-specific options for the maintenance task
     *
     * @return bool true on success, false on failure
     */
    public function perform(array $options = []): bool
    {
        $startTime = microtime(true);
        $this->logger->info('Starting Redis cache maintenance.');

        try {
            $operations = $options['operations'] ?? ['cleanup', 'defrag'];
            $success = true;

            foreach ($operations as $operation) {
                $success = match ($operation) {
                    'cleanup' => $this->performCleanup($options) && $success,
                    'defrag' => $this->performDefragmentation() && $success,
                    'memory_analysis' => $this->performMemoryAnalysis() && $success,
                    default => $this->logUnknownOperation($operation),
                };
            }

            $duration = microtime(true) - $startTime;
            $this->logger->info('Redis cache maintenance completed.', [
                'duration_ms' => round($duration * 1000, 2),
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache maintenance failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Performs cleanup of expired keys (emulated via a script if needed).
     * Redis handles expiration automatically, so this is often for custom cleanup patterns.
     *
     * @param array<string, mixed> $options
     */
    private function performCleanup(array $options): bool
    {
        $pattern = $options['cleanup_pattern'] ?? $this->config->keyPrefix . '*:expired:*';
        $this->logger->debug('Performing cleanup', ['pattern' => $pattern]);

        try {
            // Using a Lua script for atomic cleanup is safer than scanning and deleting.
            $script = '
                local keys = redis.call("keys", ARGV[1])
                if #keys > 0 then
                    return redis.call("del", unpack(keys))
                end
                return 0';
            $this->redis->eval($script, [$pattern], 0);

            return true;
        } catch (\RedisException $e) {
            $this->logger->error('Cleanup operation failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Triggers memory defragmentation on the Redis server.
     */
    private function performDefragmentation(): bool
    {
        $this->logger->debug('Performing memory defragmentation');
        try {
            // MEMORY PURGE is the command for active defragmentation.
            $result = $this->redis->rawCommand('MEMORY', 'PURGE');

            return false !== $result;
        } catch (\RedisException $e) {
            $this->logger->warning('Memory defragmentation not supported or failed', ['error' => $e->getMessage()]);

            // Return true as it's not a critical failure if the command isn't supported.
            return true;
        }
    }

    /**
     * Performs a memory analysis and logs the information.
     */
    private function performMemoryAnalysis(): bool
    {
        $this->logger->debug('Performing memory analysis');
        try {
            $memoryInfo = $this->redis->info('memory');
            if (is_array($memoryInfo)) {
                $this->logger->info('Redis memory analysis', $memoryInfo);

                return true;
            }

            return false;
        } catch (\RedisException $e) {
            $this->logger->error('Memory analysis failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function logUnknownOperation(string $operation): bool
    {
        $this->logger->warning('Unknown maintenance operation requested', ['operation' => $operation]);

        return true; // Do not fail the entire maintenance run for an unknown operation.
    }
}
