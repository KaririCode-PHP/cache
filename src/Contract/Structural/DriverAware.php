<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use KaririCode\Cache\Contract\CacheDriver;

/**
 * DriverAware interface.
 *
 * This interface defines methods for managing cache drivers.
 * Drivers are responsible for implementing the actual storage
 * mechanisms and communication with cache backends.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface DriverAware
{
    /**
     * Sets the cache driver for the current instance.
     *
     * @param CacheDriver $driver the cache driver to set
     *
     * @return DriverAware returns the current instance to allow method chaining
     */
    public function setDriver(CacheDriver $driver): DriverAware;

    /**
     * Retrieves the current cache driver.
     *
     * @return CacheDriver the current cache driver
     */
    public function getDriver(): CacheDriver;

    /**
     * Checks if a driver is currently set.
     *
     * @return bool true if driver is set, false otherwise
     */
    public function hasDriver(): bool;

    /**
     * Gets the driver type/name.
     *
     * @return string the driver type identifier
     */
    public function getDriverType(): string;
}
