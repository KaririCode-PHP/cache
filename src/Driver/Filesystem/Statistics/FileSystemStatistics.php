<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Statistics;

use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Contract\Filesystem\FileSystemStatistics as FileSystemStatisticsContract;
use Psr\Log\LoggerInterface;

/**
 * Statistics collector following SRP.
 * Responsible ONLY for collecting and providing statistics.
 */
final class FileSystemStatistics implements FileSystemStatisticsContract
{
    /** @var array<string, int> */
    private array $operationCounts = [];

    /** @var array<string, list<float>> */
    private array $operationTimes = [];

    private float $startTime;

    public function __construct(
        private DirectoryManager $directoryManager,
        private FileManager $fileManager,
        private LoggerInterface $logger
    ) {
        $this->startTime = microtime(true);
        $this->initializeCounters();
    }

    /**
     * @return array{
     * directory_size: int,
     * file_count: int,
     * uptime: float,
     * operation_counts: array<string, int>,
     * average_operation_times: array<string, float>,
     * disk_free_space?: int|float|false,
     * disk_total_space?: int|float|false,
     * error?: string
     * }
     */
    public function getStats(): array
    {
        try {
            $baseStats = [
                'directory_size' => $this->directoryManager->getSize(),
                'file_count' => $this->directoryManager->getFileCount(),
                'uptime' => microtime(true) - $this->startTime,
                'operation_counts' => $this->operationCounts,
                'average_operation_times' => $this->calculateAverageOperationTimes(),
            ];

            if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
                $baseStats['disk_free_space'] = disk_free_space($this->directoryManager->getBaseDirectory());
                $baseStats['disk_total_space'] = disk_total_space($this->directoryManager->getBaseDirectory());
            }

            return $baseStats;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to collect statistics', ['error' => $e->getMessage()]);

            // Correção: Retorna a mesma estrutura do `try`, com valores padrão em caso de erro.
            return [
                'directory_size' => 0,
                'file_count' => 0,
                'uptime' => microtime(true) - $this->startTime,
                'operation_counts' => $this->operationCounts,
                'average_operation_times' => $this->calculateAverageOperationTimes(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{
     * status: string,
     * write_success?: bool,
     * read_success?: bool,
     * delete_success?: bool,
     * latency_ms?: float,
     * directory_writable?: bool,
     * error?: string
     * }
     */
    public function healthCheck(): array
    {
        try {
            $testKey = 'health_check_' . uniqid('', true);
            $tempFile = sys_get_temp_dir() . '/' . $testKey;
            $startTime = microtime(true);

            $writeSuccess = $this->fileManager->writeSecurely($tempFile, 'health_check_data');
            $readData = $this->fileManager->readSecurely($tempFile);
            $readSuccess = 'health_check_data' === $readData;
            $deleteSuccess = $this->fileManager->deleteFile($tempFile);
            $latency = microtime(true) - $startTime;

            return [
                'status' => $writeSuccess && $readSuccess && $deleteSuccess ? 'healthy' : 'unhealthy',
                'write_success' => $writeSuccess,
                'read_success' => $readSuccess,
                'delete_success' => $deleteSuccess,
                'latency_ms' => round($latency * 1000, 2),
                'directory_writable' => $this->directoryManager->isWritable(sys_get_temp_dir()),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function recordOperation(string $operation, float $duration): void
    {
        if (!isset($this->operationCounts[$operation])) {
            $this->initializeCountersForOperation($operation);
        }

        ++$this->operationCounts[$operation];
        $this->operationTimes[$operation][] = $duration;

        if (count($this->operationTimes[$operation]) > 100) {
            $this->operationTimes[$operation] = array_slice($this->operationTimes[$operation], -100);
        }
    }

    private function initializeCounters(): void
    {
        $operations = ['get', 'set', 'has', 'delete', 'clear'];
        foreach ($operations as $operation) {
            $this->initializeCountersForOperation($operation);
        }
    }

    private function initializeCountersForOperation(string $operation): void
    {
        $this->operationCounts[$operation] = 0;
        $this->operationTimes[$operation] = [];
    }

    /**
     * @return array<string, float>
     */
    private function calculateAverageOperationTimes(): array
    {
        $averages = [];
        foreach ($this->operationTimes as $operation => $times) {
            if (!empty($times)) {
                $averages[$operation] = array_sum($times) / count($times);
            } else {
                $averages[$operation] = 0.0;
            }
        }

        return $averages;
    }
}
