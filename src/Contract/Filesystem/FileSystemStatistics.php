<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

/**
 * Interface FileSystemStatistics.
 *
 * Defines the contract for collecting and providing statistics and health
 * check information for a filesystem-based cache.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface FileSystemStatistics
{
    /**
     * Gets cache statistics.
     *
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
    public function getStats(): array;

    /**
     * Performs a health check.
     *
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
    public function healthCheck(): array;

    /**
     * Records a cache operation for metrics.
     *
     * @param string $operation The name of the operation (e.g., 'get', 'set').
     * @param float $duration the duration of the operation in seconds
     */
    public function recordOperation(string $operation, float $duration): void;
}
