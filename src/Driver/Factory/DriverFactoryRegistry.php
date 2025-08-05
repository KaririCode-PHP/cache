<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\DriverManager as DriverManagerContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\CapabilityAwareDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;

/**
 * Registry for cache driver factories with auto-discovery and capability wrapping.
 * UPDATED: Now properly handles ExpirationProvider for all drivers.
 */
final class DriverFactoryRegistry
{
    /** @var array<string, class-string<CacheDriverFactory>> */
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
     * UPDATED: Now properly creates and injects ExpirationProvider.
     *
     * @param array<string, mixed> $config
     */
    public function createDriver(
        string $name,
        array $config = [],
        ?CacheSerializer $serializer = null,
        ?ExpirationProvider $expirationProvider = null
    ): CacheDriver {
        if (!isset($this->factories[$name])) {
            throw ConfigurationException::missingDriver($name);
        }

        if (null !== $expirationProvider) {
            $config['expiration_provider'] = $expirationProvider;
        } elseif (!isset($config['expiration_provider'])) {
            // Create default ExpirationProvider if none provided
            $config['expiration_provider'] = $this->createDefaultExpirationProvider($config);
        }

        $factoryClass = $this->factories[$name];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        if (!$factory->validateOptions($config)) {
            throw ConfigurationException::invalidConfiguration($name, 'Invalid driver options provided');
        }

        $manager = $this->createDriverManager($serializer);

        $driver = $factory->createDriver($config, $manager);

        if ($this->wrapWithCapabilities) {
            return new CapabilityAwareDriver($driver);
        }

        return $driver;
    }

    /**
     * Get all registered driver names.
     *
     * @return string[]
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
     *
     * @return string[]
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
            'layered' => LayeredDriverFactory::class,
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
    private function createDriverManager(?CacheSerializer $serializer = null): DriverManagerContract
    {
        $manager = new DriverManager();
        if ($serializer) {
            $manager->setSerializer($serializer);
        }

        return $manager;
    }

    /**
     * Create default ExpirationProvider with sensible defaults.
     */
    private function createDefaultExpirationProvider(array $config): ExpirationProvider
    {
        return new ConfigurableExpirationProvider(
            $config['default_ttl'] ?? 3600,
            $config['operation_ttls'] ?? [
                'set' => 3600,
                'setMultiple' => 3600,
                'add' => 3600,
                'increment' => 7200,
                'decrement' => 7200,
                'setWithTags' => 1800,
                'compareAndSwap' => 1800,
            ],
            $config['key_pattern_ttls'] ?? [
                'session:*' => 7200,
                'api:*' => 1800,
                'temp:*' => 300,
                'user:*' => 900,
                'config:*' => 86400,
            ]
        );
    }
}
