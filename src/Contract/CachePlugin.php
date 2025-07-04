<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Config\DriverManager;

interface CachePlugin
{
    public function register(DriverManager $driverManager): void;

    public function getName(): string;

    public function getDriverName(): string;
}
