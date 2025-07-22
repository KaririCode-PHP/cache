<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Component;

use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Handles monitoring, statistics, and maintenance operations.
 * Single Responsibility: Monitoring, metrics, and maintenance.
 */
final class RedisMonitoringService
{
    public function __construct(
        private \Redis $redis,
        private RedisErrorHandler $errorHandler,
        private LoggerInterface $logger
    ) {
    }

    public function getStats(): DriverStats
    {
        try {
            $info = $this->redis->info();
            $dbsize = $this->redis->dbSize();

            if (!is_array($info)) {
                return new MetricsSnapshot('Redis', null, null, null, ['error' => 'Failed to retrieve Redis info']);
            }

            return new MetricsSnapshot(
                'Redis',
                $info['used_memory'] ?? null,
                is_int($dbsize) ? $dbsize : null,
                $info['redis_version'] ?? null,
                $info
            );
        } catch (\RedisException $e) {
            $this->logger->error('Failed to retrieve Redis statistics', ['error' => $e->getMessage()]);

            return new MetricsSnapshot('Redis', null, null, null, ['error' => $e->getMessage()]);
        }
    }

    public function performMaintenance(array $options = []): bool
    {
        $startTime = microtime(true);
        $this->logger->info('Starting Redis cache maintenance.');

        try {
            $operations = $options['operations'] ?? ['cleanup'];
            $success = true;

            foreach ($operations as $operation) {
                switch ($operation) {
                    case 'cleanup':
                        $success = $this->performCleanup($options) && $success;
                        break;
                    case 'defrag':
                        $success = $this->performDefragmentation($options) && $success;
                        break;
                    case 'memory_analysis':
                        $success = $this->performMemoryAnalysis($options) && $success;
                        break;
                    default:
                        $this->logger->warning("Unknown maintenance operation: {$operation}");
                }
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

    private function performCleanup(array $options): bool
    {
        try {
            // Exemplo de operação de limpeza
            $this->redis->eval('
                local keys = redis.call("keys", ARGV[1])
                if #keys > 0 then
                    return redis.call("del", unpack(keys))
                end
                return 0
            ', [], ['*:expired:*']);

            return true;
        } catch (\RedisException $e) {
            $this->logger->error('Cleanup operation failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function performDefragmentation(array $options): bool
    {
        try {
            $result = $this->redis->rawCommand('MEMORY', 'PURGE');

            return false !== $result;
        } catch (\RedisException $e) {
            $this->logger->warning('Memory defragmentation not supported or failed', ['error' => $e->getMessage()]);

            return true;
        }
    }

    private function performMemoryAnalysis(array $options): bool
    {
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
}
