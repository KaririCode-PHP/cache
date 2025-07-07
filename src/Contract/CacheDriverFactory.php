<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Factory interface for creating cache drivers.
 */
interface CacheDriverFactory
{
    /**
     * Create a cache driver instance.
     */
    public function createDriver(array $options, DriverManager $manager): CacheDriver;

    /**
     * Get supported configuration options.
     */
    public function getSupportedOptions(): array;

    /**
     * Validate configuration options.
     */
    public function validateOptions(array $options): bool;
}
