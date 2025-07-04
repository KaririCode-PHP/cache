<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Config\DriverManager;

interface CacheDriverFactory
{
    public function createDriver(
        array $options,
        DriverManager $driverManager
    ): CacheDriver;
}
