<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\CacheTransactionManager;
use KaririCode\Cache\Contract\DriverManager as DriverManagerContract;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Serializer\NativeSerializer;

/**
 * Complete implementation of DriverManager.
 */
final class DriverManager implements DriverManagerContract
{
    /** @var array<string, class-string<CacheDriverFactory>> */
    private array $factories = [];

    /** @var array<string, array<string, mixed>> */
    private array $driverOptions = [];

    private ?CacheSerializer $serializer = null;
    private ?CacheTransactionManager $transactionManager = null;

    public function __construct()
    {
        $this->serializer = new NativeSerializer();
        $this->registerDefaultFactories();
    }

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

    public function createDriver(string $driver, array $options = []): CacheDriver
    {
        if (!isset($this->factories[$driver])) {
            throw ConfigurationException::missingDriver($driver);
        }

        $factoryClass = $this->factories[$driver];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        $mergedOptions = array_merge($this->getDriverOptions($driver), $options);

        // Validate options
        if (!$factory->validateOptions($mergedOptions)) {
            throw ConfigurationException::invalidConfiguration($driver, 'Invalid driver options');
        }

        return $factory->createDriver($mergedOptions, $this);
    }

    public function setSerializer(CacheSerializer $serializer): void
    {
        $this->serializer = $serializer;
    }

    public function getSerializer(): ?CacheSerializer
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

    /**
     * @return array<string>
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->factories);
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->factories[$driver]);
    }

    private function registerDefaultFactories(): void
    {
        $defaultFactories = [
            'redis' => 'KaririCode\Cache\Driver\Factory\RedisDriverFactory',
            'memcached' => 'KaririCode\Cache\Driver\Factory\MemcachedDriverFactory',
            'apcu' => 'KaririCode\Cache\Driver\Factory\ApcuDriverFactory',
            'filesystem' => 'KaririCode\Cache\Driver\Factory\FilesystemDriverFactory',
            'array' => 'KaririCode\Cache\Driver\Factory\ArrayDriverFactory',
        ];

        foreach ($defaultFactories as $name => $factory) {
            if (class_exists($factory)) {
                $this->factories[$name] = $factory;
            }
        }
    }
}
