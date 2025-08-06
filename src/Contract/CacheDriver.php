<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\MultiOperationCacheable;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Interface CacheDriver.
 *
 * Defines the contract for cache drivers.
 * Cache drivers are responsible for implementing the actual storage
 * mechanism and communication with specific cache backends like Redis,
 * Memcached, filesystem, or in-memory arrays.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheDriver extends MultiOperationCacheable, CacheItemPoolInterface
{
    /**
     * Gets the supported capabilities of this driver.
     *
     * @return list<string> list of supported capabilities using CacheCapabilities constants
     */
    public function getCapabilities(): array;

    /**
     * Gets driver-specific statistics.
     *
     * @return DriverStats driver statistics as an immutable object
     */
    public function getStats(): DriverStats;
}
