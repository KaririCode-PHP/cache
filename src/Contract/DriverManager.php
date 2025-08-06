<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface DriverManager.
 *
 * Defines a contract for a driver manager, which provides access to
 * system-wide components like serializers and transaction managers.
 * This is used for dependency injection into cache drivers.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface DriverManager
{
    /**
     * Creates a cache driver instance.
     *
     * @param string $driver the name of the driver
     * @param array $options the configuration options for the driver
     *
     * @return CacheDriver the created cache driver instance
     */
    public function createDriver(string $driver, array $options = []): CacheDriver;

    /**
     * Gets the serializer instance.
     *
     * @return CacheSerializer|null the cache serializer instance or null if not set
     */
    public function getSerializer(): ?CacheSerializer;

    /**
     * Gets driver-specific options.
     *
     * @param string $driver the name of the driver
     *
     * @return array<string, mixed> an associative array of driver options
     */
    public function getDriverOptions(string $driver): array;
}
