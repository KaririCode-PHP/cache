<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Factory\DriverFactory;

class CacheFactory
{
    /**
     * @var array<string, DriverFactory>
     */
    private array $factories = [];

    public function __construct(private CacheConfig $config)
    {
    }

    public function registerDriver(string $name, DriverFactory $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function create(?string $driver = null): Cache
    {
        $driver = $driver ?? $this->config->getDefaultDriver();

        if (!isset($this->factories[$driver])) {
            throw new InvalidArgumentException("Unsupported cache driver: $driver");
        }

        $driverConfig = $this->config->getDriverConfig($driver);
        $cacheDriver = $this->factories[$driver]->create($driverConfig);

        return new Cache($cacheDriver);
    }
}
