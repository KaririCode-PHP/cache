<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CacheTransactionManager;
use KaririCode\Cache\Contract\DriverManager as DriverManagerContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\ConfigurationException;

class DriverManager implements DriverManagerContract
{
    /** @var array<string, class-string<CacheDriverFactory>> */
    private array $factories = [];

    private array $driverOptions = [];
    private ?Serializer $serializer = null;
    private ?CacheTransactionManager $transactionManager = null;

    public function addFactory(string $driver, string $factoryClass): void
    {
        if (!is_subclass_of($factoryClass, CacheDriverFactory::class)) {
            throw ConfigurationException::invalidFactory($driver, $factoryClass, CacheDriverFactory::class);
        }
        $this->factories[$driver] = $factoryClass;
    }

    public function setDriverOptions(string $driver, array $options): void
    {
        $this->driverOptions[$driver] = $options;
    }

    public function getDriverOptions(string $driver): array
    {
        return $this->driverOptions[$driver] ?? [];
    }

    public function createDriver(string $driver, array $options): CacheDriver
    {
        if (!isset($this->factories[$driver])) {
            throw new ConfigurationException("No factory registered for driver: $driver");
        }

        $factoryClass = $this->factories[$driver];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        $mergedOptions = array_merge($this->getDriverOptions($driver), $options);

        return $factory->createDriver($mergedOptions, $this);
    }

    public function setSerializer(Serializer $serializer): void
    {
        $this->serializer = $serializer;
    }

    public function getSerializer(): ?Serializer
    {
        return $this->serializer;
    }

    public function setTransactionManager(CacheTransactionManager $transactionManager): void
    {
        $this->transactionManager = $transactionManager;
    }

    public function getTransactionManager(): ?CacheTransactionManager
    {
        return $this->transactionManager;
    }
}
