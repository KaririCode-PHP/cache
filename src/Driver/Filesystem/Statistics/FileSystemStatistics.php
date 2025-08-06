<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Statistics;

use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Service\DTO\HealthCheckResult;
use Psr\Log\LoggerInterface;

/**
 * Statistics collector for the FilesystemDriver.
 *
 * This class is solely responsible for collecting and providing statistics about
 * the filesystem cache, such as size, file count, and operation metrics.
 * It implements the DriverStats interface for consistency with other drivers.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FileSystemStatistics implements DriverStats
{
    /** @var array<string, int> */
    private array $operationCounts = [];

    /** @var array<string, list<float>> */
    private array $operationTimes = [];

    private float $startTime;

    public function __construct(
        private readonly DirectoryManager $directoryManager,
        private readonly FileManager $fileManager,
        private readonly LoggerInterface $logger
    ) {
        $this->startTime = microtime(true);
    }

    // --- DriverStats Interface Methods ---

    public function getDriverName(): string
    {
        return 'Filesystem';
    }

    public function getDriverVersion(): ?string
    {
        return '2.0.0'; // Example version
    }

    public function getMemoryUsage(): ?int
    {
        // Memory usage is not directly applicable to the filesystem driver in the same way as in-memory caches.
        // We can return the total directory size as a representation of its "footprint".
        return $this->directoryManager->getSize();
    }

    public function getItemCount(): ?int
    {
        return $this->directoryManager->getFileCount();
    }

    public function getRawStats(): array
    {
        try {
            $baseStats = [
                'directory_size' => $this->directoryManager->getSize(),
                'file_count' => $this->directoryManager->getFileCount(),
                'uptime_seconds' => microtime(true) - $this->startTime,
                'operation_counts' => $this->operationCounts,
                'average_operation_times_ms' => $this->calculateAverageOperationTimes(),
            ];

            if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
                $baseDir = $this->directoryManager->getBaseDirectory();
                $baseStats['disk_free_space_bytes'] = disk_free_space($baseDir);
                $baseStats['disk_total_space_bytes'] = disk_total_space($baseDir);
            }

            return $baseStats;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to collect filesystem statistics', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    // --- Public Methods for Operation Recording ---

    public function recordOperation(string $operation, float $duration): void
    {
        $this->operationCounts[$operation] = ($this->operationCounts[$operation] ?? 0) + 1;
        $this->operationTimes[$operation][] = $duration;

        // To save memory, keep only the last 1000 duration records per operation.
        if (count($this->operationTimes[$operation]) > 1000) {
            array_shift($this->operationTimes[$operation]);
        }
    }

    // --- Health Check (Should be moved to a dedicated HealthCheckService) ---

    public function healthCheck(): HealthCheckResult
    {
        $startTime = microtime(true);
        try {
            $baseDir = $this->directoryManager->getBaseDirectory();
            if (!$this->directoryManager->isWritable($baseDir)) {
                throw new \RuntimeException("Directory is not writable: {$baseDir}");
            }

            $testKey = 'health_check_' . uniqid('', true);
            $tempFile = $baseDir . DIRECTORY_SEPARATOR . $testKey;

            $writeSuccess = $this->fileManager->writeSecurely($tempFile, 'ok');
            $readData = $this->fileManager->readSecurely($tempFile);
            $readSuccess = 'ok' === $readData;
            $deleteSuccess = $this->fileManager->deleteFile($tempFile);

            $isHealthy = $writeSuccess && $readSuccess && $deleteSuccess;
            $message = $isHealthy ? 'OK' : 'Read/Write/Delete test failed.';

            return new HealthCheckResult(
                'Filesystem I/O',
                $isHealthy,
                $message,
                round((microtime(true) - $startTime) * 1000, 2),
                time()
            );
        } catch (\Throwable $e) {
            return new HealthCheckResult(
                'Filesystem I/O',
                false,
                $e->getMessage(),
                round((microtime(true) - $startTime) * 1000, 2),
                time()
            );
        }
    }

    /**
     * @return array<string, float>
     */
    private function calculateAverageOperationTimes(): array
    {
        $averages = [];
        foreach ($this->operationTimes as $operation => $times) {
            if (!empty($times)) {
                $averages[$operation] = (array_sum($times) / count($times)) * 1000; // in ms
            } else {
                $averages[$operation] = 0.0;
            }
        }

        return $averages;
    }
}
