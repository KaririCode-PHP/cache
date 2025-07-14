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
     * Gets the serializer instance.
     *
     * @return CacheSerializer|null the cache serializer instance or null if not set
     */
    public function getSerializer(): ?CacheSerializer;

    /**
     * Gets the transaction manager instance.
     *
     * @return CacheTransactionManager|null the transaction manager instance or null if not set
     */
    public function getTransactionManager(): ?CacheTransactionManager;

    /**
     * Gets driver-specific options.
     *
     * @param string $driver the name of the driver
     *
     * @return array an array of options for the specified driver
     */
    public function getDriverOptions(string $driver): array;
}
