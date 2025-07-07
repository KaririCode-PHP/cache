<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Driver manager interface for dependency injection.
 */
interface DriverManager
{
    /**
     * Get serializer instance.
     */
    public function getSerializer(): ?Serializer;

    /**
     * Get transaction manager instance.
     */
    public function getTransactionManager(): ?CacheTransactionManager;

    /**
     * Get driver-specific options.
     */
    public function getDriverOptions(string $driver): array;
}
