<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\CacheConfigurable;
use KaririCode\Cache\Contract\Structural\CacheAware;
use KaririCode\Cache\Contract\Structural\DriverAware;
use KaririCode\Cache\Contract\Structural\EventDispatcherAware;
use KaririCode\Cache\Contract\Structural\MetricsAware;
use KaririCode\Cache\Contract\Structural\SerializerAware;
use KaririCode\Cache\Contract\Structural\TransactionAware;
use KaririCode\Cache\Contract\Structural\ValidatorAware;
use KaririCode\Contract\ImmutableValue;

/**
 * Interface CacheManager.
 *
 * Comprehensive cache manager interface that combines all cache management
 * functionalities including driver management, configuration, metrics,
 * serialization, validation, transactions, and event dispatching.
 *
 * This interface serves as the main orchestrator for cache operations
 * and provides a centralized point for cache system management.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheManager extends
    CacheAware,
    DriverAware,
    SerializerAware,
    ValidatorAware,
    MetricsAware,
    TransactionAware,
    EventDispatcherAware,
    CacheConfigurable
{
    /**
     * Creates a new cache instance with the specified configuration.
     *
     * @param string $name the cache instance name
     * @param array $config the cache configuration
     *
     * @return Cache the created cache instance
     */
    public function createCache(string $name, array $config = []): Cache;

    /**
     * Removes a cache instance.
     *
     * @param string $name the cache instance name
     *
     * @return bool true if removed successfully, false otherwise
     */
    public function removeCache(string $name): bool;

    /**
     * Gets all managed cache instances.
     *
     * @return array<string, Cache> associative array of cache instances
     */
    public function getAllCaches(): array;

    /**
     * Gets the names of all managed cache instances.
     *
     * @return array list of cache instance names
     */
    public function getCacheNames(): array;

    /**
     * Creates a cache driver with the specified configuration.
     *
     * @param string $type the driver type
     * @param array $config the driver configuration
     *
     * @return CacheDriver the created driver instance
     */
    public function createDriver(string $type, array $config = []): CacheDriver;

    /**
     * Gets all supported driver types.
     *
     * @return array list of supported driver types
     */
    public function getSupportedDrivers(): array;

    /**
     * Gets the default cache instance.
     *
     * @return Cache the default cache instance
     */
    public function getDefaultCache(): Cache;

    /**
     * Sets the default cache instance name.
     *
     * @param string $name the cache instance name to set as default
     */
    public function setDefaultCache(string $name): void;

    /**
     * Gets comprehensive management statistics.
     *
     * @return ImmutableValue management statistics as an immutable object
     */
    public function getManagementStats(): ImmutableValue;

    /**
     * Performs health check on all managed caches.
     *
     * @return ImmutableValue health check results as an immutable object
     */
    public function healthCheck(): ImmutableValue;

    /**
     * Performs maintenance on all managed caches.
     *
     * @param array $options maintenance options
     *
     * @return bool true if all maintenance operations succeeded, false otherwise
     */
    public function performMaintenance(array $options = []): bool;
}
