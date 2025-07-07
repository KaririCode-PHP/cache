<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\ArrayDriver;

/**
 * Array driver factory for testing and request-scoped caching.
 */
final class ArrayDriverFactory implements CacheDriverFactory
{
    public function create(array $config): CacheDriver
    {
        return new ArrayDriver();
    }

    public function createDriver(array $config): CacheDriver
    {
        return $this->create($config);
    }

    public function getSupportedOptions(): array
    {
        // ArrayDriver typicaslly has no configurable options
        return [];
    }

    public function validateOptions(array $options): bool
    {
        // No options to validate for ArrayDriver
        return true;
    }
}
