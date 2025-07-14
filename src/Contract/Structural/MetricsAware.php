<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use KaririCode\Cache\Contract\CacheMetricsCollector;

/**
 * MetricsAware interface.
 *
 * This interface defines methods for managing metrics collectors.
 * Metrics collectors are responsible for gathering and reporting
 * cache performance and operational statistics.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface MetricsAware
{
    /**
     * Sets the metrics collector for the current instance.
     *
     * @param CacheMetricsCollector $metricsCollector the metrics collector to set
     *
     * @return MetricsAware returns the current instance to allow method chaining
     */
    public function setMetricsCollector(CacheMetricsCollector $metricsCollector): MetricsAware;

    /**
     * Retrieves the current metrics collector.
     *
     * @return CacheMetricsCollector the current metrics collector
     */
    public function getMetricsCollector(): CacheMetricsCollector;

    /**
     * Checks if a metrics collector is currently set.
     *
     * @return bool true if metrics collector is set, false otherwise
     */
    public function hasMetricsCollector(): bool;
}
