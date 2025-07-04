<?php

declare(strict_types=1);

namespace KaririCode\Cache\Plugin;

use KaririCode\Cache\Attribute\DriverFactory;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Driver\Factory\MemcachedDriverFactory;

#[DriverFactory(MemcachedDriverFactory::class)]
class MemcachedDriverPlugin extends AbstractPlugin
{
    public function register(DriverManager $driverManager): void
    {
        $driverManager->addFactory(
            $this->getDriverName(),
            $this->getFactoryClass()
        );
    }

    public function getDriverName(): string
    {
        return 'memcached';
    }
}
