<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\CacheConfiguration;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\Behavioral\MultiOperationCacheable;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Central Cache Manager - Facade Pattern for cache operations.
 *
 * This class serves as the main entry point for all cache operations in the application.
 * It manages multiple cache stores, handles driver creation, and provides a unified
 * interface for cache operations.
 *
 * Updated to work seamlessly with both CacheConfig (KaririCode\Configurator integration)
 * and traditional array configuration for maximum flexibility.
 *
 * Design Patterns:
 * - Facade Pattern: Provides simplified interface to complex subsystem
 * - Factory Pattern: Creates and manages cache drivers
 * - Strategy Pattern: Different cache stores for different needs
 * - Adapter Pattern: Adapts between CacheConfig and CacheConfiguration
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class CacheManager implements Cache, MultiOperationCacheable
{
    /** @var array<string, CacheDriver> Instantiated cache drivers */
    private array $stores = [];

    private readonly string $defaultStore;
    private readonly CacheConfiguration $configuration;
    private readonly DriverManager $driverManager;
    private readonly CacheSerializer $serializationService;
    private readonly LoggerInterface $logger;
    private readonly ExpirationProvider $expirationProvider;

    /**
     * Create CacheManager from various configuration sources.
     *
     * @param CacheConfig|CacheConfiguration|array $config Configuration source
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        CacheConfig|CacheConfiguration|array $config,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();

        // Handle different configuration types
        $this->configuration = match (true) {
            $config instanceof CacheConfig => $config->toCacheConfiguration(),
            $config instanceof CacheConfiguration => $config,
            is_array($config) => new CacheConfiguration($config),
            default => throw new \InvalidArgumentException('Invalid configuration type')
        };

        $this->defaultStore = $this->configuration->getDefaultStore();

        // Initialize core services
        $this->driverManager = new DriverManager();
        $this->serializationService = $this->createSerializationService();
        $this->expirationProvider = $this->createExpirationProvider();

        // Configure driver manager
        $this->configureDriverManager();

        $this->logger->info('CacheManager initialized', [
            'default_store' => $this->defaultStore,
            'available_stores' => array_keys($this->configuration->getStores()),
            'serializers' => array_keys($this->configuration->getSerializers()),
        ]);
    }

    /**
     * Get a specific cache store.
     *
     * @param string|null $name Store name, or null for default
     *
     * @throws ConfigurationException If store doesn't exist
     */
    public function store(?string $name = null): CacheDriver
    {
        $name = $name ?? $this->defaultStore;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        $storeConfig = $this->configuration->getStore($name);
        if (!$storeConfig) {
            throw ConfigurationException::missingStore($name);
        }

        $this->stores[$name] = $this->createDriver($storeConfig);

        $this->logger->debug('Cache store created', [
            'store' => $name,
            'driver' => $storeConfig->getDriver(),
        ]);

        return $this->stores[$name];
    }

    /**
     * Get default store.
     */
    public function getDefaultStore(): CacheDriver
    {
        return $this->store();
    }

    /**
     * Check if a store exists.
     */
    public function hasStore(string $name): bool
    {
        return $this->configuration->hasStore($name);
    }

    /**
     * Get all available store names.
     */
    public function getAvailableStores(): array
    {
        return array_keys($this->configuration->getStores());
    }

    /**
     * Get configuration.
     */
    public function getConfiguration(): CacheConfiguration
    {
        return $this->configuration;
    }

    /**
     * Get expiration provider.
     */
    public function getExpirationProvider(): ExpirationProvider
    {
        return $this->expirationProvider;
    }

    // Delegate methods to default store
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getDefaultStore()->get($key, $default);
    }

    public function set(string $key, mixed $value): bool
    {
        return $this->getDefaultStore()->set($key, $value);
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

    public function setMultiple(iterable $values): bool
    {
        return $this->getDefaultStore()->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->getDefaultStore()->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->getDefaultStore()->has($key);
    }

    public function getSerializationService(): SerializationService
    {
        return $this->serializationService;
    }

    private function createDriver($storeConfig): CacheDriver
    {
        $driver = $storeConfig->getDriver();
        $options = $storeConfig->getOptions();

        // Add global configurations
        $options['expiration_provider'] = $this->expirationProvider;
        $options['serializer'] = $this->serializationService;
        $options['logger'] = $this->logger;

        // Add feature flags
        $features = $this->configuration->getFeatures();
        $options['features'] = $features->toArray();

        // Add validation rules
        $validation = $this->configuration->getValidation();
        $options['validation'] = $validation->toArray();

        // Add driver-specific optimizations
        $optimizations = $this->configuration->getOptimizations();
        $driverOptimizations = $optimizations->getDriverOptimizations($driver);
        if (!empty($driverOptimizations)) {
            $options = array_merge($options, $driverOptimizations);
        }

        return $this->driverManager->createDriver($driver, $options);
    }

    private function createSerializationService(): SerializationService
    {
        $serializationService = new SerializationService($this->logger);

        return $serializationService;
    }

    private function createExpirationProvider(): ExpirationProvider
    {
        $config = $this->configuration->getExpirationProvider();

        return ConfigurableExpirationProvider::fromConfig($config);
    }

    private function configureDriverManager(): void
    {
        // Set global serialization service
        $this->driverManager->setSerializer($this->serializationService);

        // Pre-configure driver options for each store
        foreach ($this->configuration->getStores() as $name => $storeConfig) {
            $this->driverManager->setDriverOptions($storeConfig->getDriver(), $storeConfig->getOptions());
        }
    }
}
