<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\CapabilityAwareDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Registry for cache driver factories with auto-discovery and capability wrapping.
 */
final class DriverFactoryRegistry
{
    /** @var array<string, string> */
    private array $factories = [];

    private bool $wrapWithCapabilities = true;

    public function __construct(bool $wrapWithCapabilities = true)
    {
        $this->wrapWithCapabilities = $wrapWithCapabilities;
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
     * Create a driver instance with optional capability wrapping.
     */
    public function createDriver(string $name, array $config = []): CacheDriver
    {
        if (!isset($this->factories[$name])) {
            throw ConfigurationException::missingDriver($name);
        }

        $factoryClass = $this->factories[$name];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        // Validate configuration
        if (!$factory->validateOptions($config)) {
            throw ConfigurationException::invalidConfiguration($name, 'Invalid driver options provided');
        }

        // Create a simple DriverManager for compatibility
        $manager = $this->createDriverManager();

        $driver = $factory->createDriver($config, $manager);

        // Wrap with capability awareness if enabled
        if ($this->wrapWithCapabilities) {
            return new CapabilityAwareDriver($driver);
        }

        return $driver;
    }

    /**
     * Get all registered driver names.
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Check if a driver is registered.
     */
    public function hasDriver(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * Get supported options for a driver.
     */
    public function getSupportedOptions(string $name): array
    {
        if (!isset($this->factories[$name])) {
            throw ConfigurationException::missingDriver($name);
        }

        $factoryClass = $this->factories[$name];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        return $factory->getSupportedOptions();
    }

    /**
     * Enable or disable capability wrapping.
     */
    public function setCapabilityWrapping(bool $enabled): void
    {
        $this->wrapWithCapabilities = $enabled;
    }

    /**
     * Register default factories for built-in drivers.
     */
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

    /**
     * Create a simple DriverManager implementation for factories.
     */
    private function createDriverManager(): DriverManager
    {
        return new class implements DriverManager {
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
    }
}
