<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriverInterface;
use KaririCode\Cache\Driver\ArrayDriver;

/**
 * Array driver factory for testing and request-scoped caching.
 */
final class ArrayDriverFactory
{
    public function create(array $config): CacheDriverInterface
    {
        return new ArrayDriver();
    }
}
