<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Factory interface for creating cache drivers.
 *
 * This interface defines the contract for factories that are responsible
 * for creating instances of cache drivers based on provided options.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheDriverFactory
{
    /**
     * Creates a cache driver instance.
     *
     * @param array $options the configuration options for the driver
     * @param DriverManager $manager the driver manager for dependency injection
     *
     * @return CacheDriver the created cache driver instance
     */
    public function createDriver(array $options, DriverManager $manager): CacheDriver;

    /**
     * Gets the supported configuration options for the driver.
     *
     * @return list<string> a list of supported option keys
     */
    public function getSupportedOptions(): array;

    /**
     * Validates the given configuration options.
     *
     * @param array $options the configuration options to validate
     *
     * @return bool true if the options are valid, false otherwise
     */
    public function validateOptions(array $options): bool;
}
