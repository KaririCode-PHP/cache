<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

use KaririCode\Contract\ImmutableValue;

/**
 * CacheConfigurable interface.
 *
 * This interface defines methods for managing cache configuration.
 * Configuration settings can affect the behavior of caching operations,
 * including TTL defaults, storage limits, and driver-specific options.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheConfigurable
{
    /**
     * Retrieves the current cache configuration.
     *
     * @return ImmutableValue the current configuration object
     */
    public function getConfig(): ImmutableValue;

    /**
     * Sets a new cache configuration.
     *
     * @param ImmutableValue $config the new configuration object
     *
     * @return CacheConfigurable the current instance for method chaining
     */
    public function setConfig(ImmutableValue $config): CacheConfigurable;

    /**
     * Configure cache with array-based options.
     *
     * @param array $options the configuration options
     */
    public function configure(array $options): void;
}
