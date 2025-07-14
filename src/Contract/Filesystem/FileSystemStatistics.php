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
     * @return array an associative array of statistics
     */
    public function getStats(): array;

    /**
     * Performs a health check.
     *
     * @return array an associative array with health check results
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
