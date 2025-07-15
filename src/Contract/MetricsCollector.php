<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface MetricsCollector.
 *
 * Defines the contract for collecting performance and usage metrics from a cache.
 * Implementations of this interface allow for monitoring the health and efficiency
 * of the cache by tracking hit rates, latencies, and other vital statistics.
 * This is fundamental for the observability of the system in a production environment.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface MetricsCollector
{
    /**
     * Records a cache hit event.
     *
     * @param string $key the cache key that was hit
     * @param float $duration the time taken in seconds to retrieve the item
     *
     * @return self a new instance with the updated metric
     */
    public function recordHit(string $key, float $duration): self;

    /**
     * Records a cache miss event.
     *
     * @param string $key the cache key that was missed
     *
     * @return self a new instance with the updated metric
     */
    public function recordMiss(string $key): self;

    /**
     * Records the time taken to generate a new cache value after a miss.
     *
     * @param string $key the cache key for the generated value
     * @param float $duration the time taken in seconds to generate the value
     *
     * @return self a new instance with the updated metric
     */
    public function recordGeneration(string $key, float $duration): self;

    /**
     * Records a cache clear operation.
     *
     * @return self a new instance with the updated metric
     */
    public function recordClear(): self;

    /**
     * Retrieves a snapshot of the current metrics.
     *
     * @return array{
     * hits: int,
     * misses: int,
     * hit_rate: float,
     * total_operations: int,
     * average_hit_time: float,
     * average_generation_time: float,
     * uptime: float
     * }
     */
    public function getMetrics(): array;

    /**
     * Resets all collected metrics to their initial state.
     *
     * @return self a new, reset instance
     */
    public function reset(): self;
}
