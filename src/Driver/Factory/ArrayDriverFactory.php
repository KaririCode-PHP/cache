<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\ArrayDriver;

/**
 * Array driver factory for testing and request-scoped caching.
 */
final class ArrayDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        return new ArrayDriver();
    }

    /**
     * @return list<string>
     */
    public function getSupportedOptions(): array
    {
        return [];
    }

    public function validateOptions(array $options): bool
    {
        return true;
    }
}
