<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use KaririCode\Cache\Contract\Cache;

/**
 * CacheAware interface.
 *
 * This interface defines methods for managing cache instances.
 * Enables dependency injection of cache instances into other
 * components that require caching capabilities.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheAware
{
    /**
     * Sets the cache instance for the current instance.
     *
     * @param Cache $cache the cache instance to set
     *
     * @return CacheAware returns the current instance to allow method chaining
     */
    public function setCache(Cache $cache): CacheAware;

    /**
     * Retrieves the current cache instance.
     *
     * @param string $name the name of the cache instance
     *
     * @return Cache the current cache instance
     */
    public function getCache(string $name): Cache;

    /**
     * Checks if a cache instance is currently set.
     *
     * @param string $name the name of the cache instance
     *
     * @return bool true if cache is set, false otherwise
     */
    public function hasCache(string $name): bool;
}
