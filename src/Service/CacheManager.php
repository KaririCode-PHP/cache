<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Config\StoreConfiguration;
use KaririCode\Cache\Contract\Behavioral\MultiOperationCacheable;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\Expiration\ConfigurableExpirationProvider;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\SilentLogger;

/**
 * Refactored Cache Manager following SOLID principles.
 *
 * This class now has a single responsibility: managing cache stores.
 * Configuration handling is delegated to the ConfigurationProvider interface.
 *
 * Design Patterns:
 * - Dependency Injection: Configuration injected via interface
 * - Factory Pattern: Creates and manages cache drivers
 * - Strategy Pattern: Different cache stores for different needs
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 */
final class CacheManager implements Cache, MultiOperationCacheable
{
    /** @var array<string, CacheDriver> Instantiated cache drivers */
    private array $stores = [];

    private readonly string $defaultStore;
    private readonly SerializationService $serializationService;
    private readonly ExpirationProvider $expirationProvider;

    /**
     * Constructor with dependency injection via interface.
     *
     * @param ConfigurationProvider $configuration The configuration provider
     * @param Logger|null $logger Optional logger
     */
    public function __construct(
        private readonly ConfigurationProvider $configuration,
        private readonly Logger $logger = new SilentLogger(),
        private readonly ?DriverManager $driverManager = new DriverManager()
    ) {
        $this->defaultStore = $configuration->getDefaultStore();

        // Initialize core services
        $this->serializationService = $this->createSerializationService();
        $this->expirationProvider = $this->createExpirationProvider();

        // Configure driver manager
        $this->configureDriverManager();

        $this->logger->info('CacheManager initialized', [
            'default_store' => $this->defaultStore,
            'available_stores' => array_keys($configuration->getStores()),
            'serializers' => array_keys($this->serializationService->getRegisteredSerializers()),
        ]);
    }

    public function store(?string $name = null): CacheDriver
    {
        $name = $name ?? $this->defaultStore;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        $storeConfig = $this->configuration->getStore($name);
        $this->stores[$name] = $this->createDriver($storeConfig);

        $this->logger->debug('Cache store created', [
            'store' => $name,
            'driver' => $storeConfig->getDriver(),
        ]);

        return $this->stores[$name];
    }

    public function getDefaultStore(): CacheDriver
    {
        return $this->store();
    }

    public function hasStore(string $name): bool
    {
        return $this->configuration->hasStore($name);
    }

    public function getAvailableStores(): array
    {
        return array_keys($this->configuration->getStores());
    }

    public function getConfiguration(): ConfigurationProvider
    {
        return $this->configuration;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getDefaultStore()->get($key, $default);
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return $this->getDefaultStore()->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->getDefaultStore()->delete($key);
    }

    public function clear(): bool
    {
        return $this->getDefaultStore()->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->getDefaultStore()->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        return $this->getDefaultStore()->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->getDefaultStore()->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->getDefaultStore()->has($key);
    }

    private function createDriver(StoreConfiguration $storeConfig): CacheDriver
    {
        $driver = $storeConfig->getDriver();
        $options = $storeConfig->getConfig();

        $options['expiration_provider'] = $this->expirationProvider;
        $options['serializer'] = $this->serializationService;
        $options['logger'] = $this->logger;

        $optimizations = $this->configuration->getOptimizations();
        $driverOptimizations = $optimizations->getDriverOptimizations($driver);
        if (!empty($driverOptimizations)) {
            $options = array_merge($options, $driverOptimizations);
        }

        return $this->driverManager->createDriver($driver, $options);
    }

    /**
     * Creates and configures the serialization service from the configuration.
     */
    private function createSerializationService(): SerializationService
    {
        $service = new SerializationService($this->logger);
        $service->clearSerializers(); // Ensures the configuration is the single source of truth

        $serializersConfig = $this->configuration->getSerializers();
        $defaultSerializerName = $this->configuration->getDefaultSerializer();

        foreach ($serializersConfig as $name => $config) {
            $serializerInstance = $this->buildSerializer($name, $config, $service);
            $service->registerSerializer($name, $serializerInstance);
        }

        if ($service->hasSerializer($defaultSerializerName)) {
            $service->setDefaultSerializer($defaultSerializerName);
        } elseif (!empty($service->getRegisteredSerializers())) {
            $firstAvailable = $service->getRegisteredSerializers()[0];
            $service->setDefaultSerializer($firstAvailable);
            $this->logger->warning('Default serializer not found, falling back to first available.', [
                'configured_default' => $defaultSerializerName,
                'fallback' => $firstAvailable,
            ]);
        }

        return $service;
    }

    /**
     * Builds a serializer instance from its configuration.
     * This method contains the semantic and robust logic you suggested.
     *
     * @param string $name The serializer's name (e.g., 'json_pretty')
     * @param string|array<string, mixed> $config The configuration for this serializer
     * @param SerializationService $service The serialization service (to resolve dependencies)
     *
     * @throws ConfigurationException If the configuration is invalid
     */
    private function buildSerializer(
        string $name,
        string|array $config,
        SerializationService $service
    ): CacheSerializer {
        // Case 1: Simple configuration (just the class name)
        if ($this->isSimpleConfig($config)) {
            if (!class_exists($config)) {
                throw ConfigurationException::invalidConfiguration("serializers.{$name}", "Class '{$config}' not found.");
            }

            return new $config();
        }

        // Case 2: Complex configuration (an array with 'class', 'options', etc.)
        if ($this->isComplexConfig($config)) {
            $class = $config['class'];
            $options = $config['options'] ?? [];

            if (!class_exists($class)) {
                throw ConfigurationException::invalidConfiguration("serializers.{$name}", "Class '{$class}' not found.");
            }

            // Special case: It's a Decorator that needs another serializer
            if ($this->isDecoratorConfig($config)) {
                $innerSerializerName = $config['inner'];
                $innerInstance = $service->getSerializer($innerSerializerName);

                // Build the decorator by injecting the inner instance and other options
                return new $class($innerInstance, ...$options);
            }

            // Build a normal serializer with its options
            return new $class(...$options);
        }

        throw ConfigurationException::invalidConfiguration("serializers.{$name}", 'Invalid serializer configuration format.');
    }

    // --- Semantic check methods ---

    private function isSimpleConfig(mixed $config): bool
    {
        return is_string($config);
    }

    private function isComplexConfig(mixed $config): bool
    {
        return is_array($config) && isset($config['class']) && is_string($config['class']);
    }

    private function isDecoratorConfig(array $config): bool
    {
        return isset($config['inner']) && is_string($config['inner']);
    }

    private function createExpirationProvider(): ExpirationProvider
    {
        $configDto = $this->configuration->getExpirationProvider();

        return ConfigurableExpirationProvider::fromConfig($configDto);
    }

    private function configureDriverManager(): void
    {
        $this->driverManager->setSerializer($this->serializationService);
        foreach ($this->configuration->getStores() as $storeConfig) {
            $this->driverManager->setDriverOptions(
                $storeConfig->getDriver(),
                $storeConfig->getConfig()
            );
        }
    }
}
