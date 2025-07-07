<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Registry for cache driver factories with auto-discovery.
 */
final class DriverFactoryRegistry
{
    /** @var array<string, string> */
    private array $factories = [];

    public function __construct()
    {
        $this->registerDefaultFactories();
    }

    /**
     * Register a driver factory.
     */
    public function register(string $name, string $factoryClass): void
    {
        if (!class_exists($factoryClass)) {
            throw ConfigurationException::factoryNotFound($factoryClass);
        }

        if (!is_subclass_of($factoryClass, CacheDriverFactory::class)) {
            throw ConfigurationException::invalidFactory($name, $factoryClass, CacheDriverFactory::class);
        }

        $this->factories[$name] = $factoryClass;
    }

    /**
     * Create a driver instance.
     */
    public function createDriver(string $name, array $config = []): CacheDriver
    {
        if (!isset($this->factories[$name])) {
            throw ConfigurationException::missingDriver($name);
        }

        $factoryClass = $this->factories[$name];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        // Create a simple DriverManager for compatibility
        $manager = new class implements DriverManager {
            public function getSerializer(): ?\KaririCode\Cache\Contract\Serializer
            {
                return null;
            }

            public function getTransactionManager(): ?\KaririCode\Cache\Contract\CacheTransactionManager
            {
                return null;
            }

            public function getDriverOptions(string $driver): array
            {
                return [];
            }
        };

        return $factory->createDriver($config, $manager);
    }

    /**
     * Get all registered driver names.
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->factories);
    }

    private function registerDefaultFactories(): void
    {
        $defaultFactories = [
            'redis' => RedisDriverFactory::class,
            'memcached' => MemcachedDriverFactory::class,
            'apcu' => ApcuDriverFactory::class,
            'filesystem' => FilesystemDriverFactory::class,
            'array' => ArrayDriverFactory::class,
        ];

        foreach ($defaultFactories as $name => $factory) {
            if (class_exists($factory)) {
                $this->factories[$name] = $factory;
            }
        }
    }
}
