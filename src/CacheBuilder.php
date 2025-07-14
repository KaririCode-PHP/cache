<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheEventListener as CacheEventListenerContract;
use KaririCode\Cache\Contract\CacheKeyValidator as CacheKeyValidatorContract;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Driver\Factory\DriverFactoryRegistry;
use KaririCode\Cache\Event\Factory\CacheEventFactory;
use KaririCode\Cache\Event\Listener\DefaultCacheEventListener;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Item\CachePoolManager;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\CompressingSerializer;
use KaririCode\Cache\Serializer\IgbinarySerializer;
use KaririCode\Cache\Serializer\JsonSerializer;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * CacheBuilder with proper dependency injection following SOLID principles.
 *
 * This builder receives all dependencies injected and acts as a factory
 * for Cache instances based on configuration.
 *
 * @see https://refactoring.guru/design-patterns/builder Builder Pattern
 * @see https://www.php-fig.org/psr/psr-6/ PSR-6 Cache Interface
 */
final class CacheBuilder
{
    private array $layers = [];
    private ?Serializer $serializer = null;
    private ?StampedeProtectorContract $stampedeProtector = null;
    private ?MetricsCollectorContract $metricsCollector = null;
    private ?CacheEventListenerContract $eventListener = null;
    private ?CacheKeyValidatorContract $keyValidator = null;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?ListenerProviderInterface $listenerProvider = null;
    private array $overrides = [];

    public function __construct(
        private CacheConfig $config,
        private LoggerInterface $logger,
        private DriverManager $driverManager = new DriverManager(),
        private DriverFactoryRegistry $factoryRegistry = new DriverFactoryRegistry()
    ) {
        $this->configureDriverManager();
        $this->applyConfigurationDefaults();
    }

    /**
     * Configure builder from specific store.
     */
    public function configureFromStore(string $storeName): self
    {
        if (!$this->config->has("stores.{$storeName}")) {
            throw ConfigurationException::missingDriver($storeName);
        }

        $storeConfig = $this->config->getStore($storeName);
        $this->setupFromStoreConfig($storeConfig);

        return $this;
    }

    /**
     * Configure builder from default store.
     */
    public function configureFromDefaultStore(): self
    {
        $defaultStore = $this->config->getDefaultStore();

        return $this->configureFromStore($defaultStore);
    }

    /**
     * Override serializer (dependency injection).
     */
    public function withSerializer(Serializer $serializer): self
    {
        $this->serializer = $serializer;
        $this->driverManager->setSerializer($serializer);

        return $this;
    }

    /**
     * Override stampede protector (dependency injection).
     */
    public function withStampedeProtector(?StampedeProtectorContract $stampedeProtector): self
    {
        $this->stampedeProtector = $stampedeProtector;

        return $this;
    }

    /**
     * Override metrics collector (dependency injection).
     */
    public function withMetricsCollector(?MetricsCollectorContract $metricsCollector): self
    {
        $this->metricsCollector = $metricsCollector;

        return $this;
    }

    /**
     * Override event listener (dependency injection).
     */
    public function withEventListener(?CacheEventListenerContract $eventListener): self
    {
        $this->eventListener = $eventListener;

        return $this;
    }

    /**
     * Override key validator (dependency injection).
     */
    public function withKeyValidator(?CacheKeyValidatorContract $keyValidator): self
    {
        $this->keyValidator = $keyValidator;

        return $this;
    }

    /**
     * Override PSR-14 event dispatcher (dependency injection).
     */
    public function withEventDispatcher(?EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Override PSR-14 listener provider (dependency injection).
     */
    public function withListenerProvider(?ListenerProviderInterface $listenerProvider): self
    {
        $this->listenerProvider = $listenerProvider;

        return $this;
    }

    /**
     * Override driver manager (dependency injection).
     */
    public function withDriverManager(DriverManager $driverManager): self
    {
        $this->driverManager = $driverManager;
        $this->configureDriverManager();

        return $this;
    }

    /**
     * Override logger (dependency injection).
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Enable/disable specific features.
     */
    public function withFeature(string $feature, bool $enabled): self
    {
        $this->overrides[$feature] = $enabled;

        return $this;
    }

    /**
     * Add custom layer configuration.
     */
    public function addCustomLayer(string $driver, array $config, int $priority): self
    {
        // Validate that the driver factory exists
        if (!$this->factoryRegistry->hasDriver($driver)) {
            throw ConfigurationException::missingDriver($driver);
        }

        $this->layers[] = [
            'driver' => $driver,
            'config' => $config,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * Build the cache instance.
     */
    public function build(): Cache
    {
        $this->validateConfiguration();

        $layeredCache = $this->buildLayeredCache();

        return new Cache(
            $layeredCache,
            $this->serializer,
            $this->stampedeProtector,
            $this->metricsCollector,
            $this->logger,
            $this->eventListener,
            $this->keyValidator,
            $this->eventDispatcher,
            $this->buildEffectiveConfig()
        );
    }

    /**
     * Configure the DriverManager with current dependencies.
     */
    private function configureDriverManager(): void
    {
        if ($this->serializer) {
            $this->driverManager->setSerializer($this->serializer);
        }

        // Configure driver-specific options from config
        foreach ($this->config->getStores() as $storeName => $storeConfig) {
            if (isset($storeConfig['config'])) {
                $this->driverManager->setDriverOptions($storeConfig['driver'], $storeConfig['config']);
            }
        }
    }

    /**
     * Setup builder from store configuration.
     */
    private function setupFromStoreConfig(array $storeConfig): void
    {
        $driver = $storeConfig['driver'];

        switch ($driver) {
            case 'layered':
                $this->setupLayeredCache($storeConfig);
                break;
            case 'redis':
            case 'memcached':
            case 'filesystem':
            case 'apcu':
            case 'array':
                $this->setupSingleLayerCache($driver, $storeConfig['config'] ?? []);
                break;
            default:
                throw ConfigurationException::missingDriver($driver);
        }
    }

    /**
     * Setup layered cache from configuration.
     */
    private function setupLayeredCache(array $storeConfig): void
    {
        $layers = $storeConfig['layers'] ?? [];

        foreach ($layers as $layerConfig) {
            // Validate layer configuration
            if (!isset($layerConfig['driver'])) {
                throw ConfigurationException::invalidConfiguration('layer', 'Driver not specified for layer');
            }

            $this->addCustomLayer(
                $layerConfig['driver'],
                $layerConfig['config'] ?? [],
                $layerConfig['priority'] ?? 100
            );
        }
    }

    /**
     * Setup single layer cache.
     */
    private function setupSingleLayerCache(string $driver, array $config): void
    {
        $this->addCustomLayer($driver, $config, 50);
    }

    /**
     * Apply configuration defaults by creating appropriate instances.
     */
    private function applyConfigurationDefaults(): void
    {
        // Create serializer from configuration
        if (!$this->serializer) {
            $this->serializer = $this->createSerializerFromConfig();
            $this->driverManager->setSerializer($this->serializer);
        }

        // Create stampede protector if enabled
        if ($this->config->isStampedeProtectionEnabled() && !$this->stampedeProtector) {
            $this->stampedeProtector = new StampedeProtector();
        }

        // Create metrics collector if enabled
        if ($this->config->isMetricsEnabled() && !$this->metricsCollector) {
            $this->metricsCollector = new MetricsCollector();
        }

        // Create event listener if enabled
        if ($this->config->isEventsEnabled() && !$this->eventListener) {
            $this->eventListener = new DefaultCacheEventListener($this->config->getMaxEvents());
        }

        // Create PSR-14 components if enabled
        if ($this->config->isPsr14EventsEnabled() && !$this->eventDispatcher) {
            $eventSystem = CacheEventFactory::createCompleteEventSystem();
            $this->eventDispatcher = $eventSystem['eventDispatcher'];
            $this->listenerProvider = $eventSystem['listenerProvider'];
        }

        // Create key validator if strict validation is enabled
        if ($this->config->isStrictValidationEnabled() && !$this->keyValidator) {
            $this->keyValidator = new CacheKeyValidator();
        }
    }

    /**
     * Create serializer based on configuration.
     */
    private function createSerializerFromConfig(): Serializer
    {
        $serializerType = $this->config->getSerializer();

        return match ($serializerType) {
            'igbinary' => new IgbinarySerializer(),
            'json' => new JsonSerializer(),
            'compressed' => new CompressingSerializer(new NativeSerializer()),
            'compressed_igbinary' => new CompressingSerializer(new IgbinarySerializer()),
            default => new NativeSerializer(),
        };
    }

    /**
     * Build layered cache from configuration using proper factory pattern.
     *
     * @throws ConfigurationException
     */
    private function buildLayeredCache(): LayeredCache
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'No cache layers configured. Check your configuration.');
        }

        $layeredCache = new LayeredCacheManager($this->logger);

        // Sort layers by priority (descending, higher priority first).
        usort($this->layers, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($this->layers as $layerConfig) {
            try {
                if (!$this->factoryRegistry->hasDriver($layerConfig['driver'])) {
                    throw ConfigurationException::missingDriver($layerConfig['driver']);
                }

                $driver = $this->factoryRegistry->createDriver(
                    $layerConfig['driver'],
                    $layerConfig['config']
                );

                // The CachePoolManager acts as an adapter, wrapping the low-level CacheDriver
                // to satisfy the CacheItemPool interface required by the LayeredCacheManager.
                $pool = new CachePoolManager($driver, $this->keyValidator);
                $layeredCache->addLayer($pool, $layerConfig['priority']);

                $this->logger->debug('Cache layer created', [
                    'driver' => $layerConfig['driver'],
                    'priority' => $layerConfig['priority'],
                    'config_keys' => array_keys($layerConfig['config']),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to create cache layer', [
                    'driver' => $layerConfig['driver'],
                    'error' => $e->getMessage(),
                    'config' => $layerConfig['config'],
                ]);

                throw ConfigurationException::invalidConfiguration($layerConfig['driver'], "Failed to create cache layer: {$e->getMessage()}");
            }
        }

        return $layeredCache;
    }

    /**
     * Build effective configuration combining config and overrides.
     */
    private function buildEffectiveConfig(): array
    {
        $baseConfig = [
            'default_ttl' => $this->config->getDefaultTtl(),
            'sliding_expiration' => $this->config->isSlidingExpirationEnabled(),
            'stampede_protection' => $this->config->isStampedeProtectionEnabled(),
            'metrics_enabled' => $this->config->isMetricsEnabled(),
            'events_enabled' => $this->config->isEventsEnabled(),
            'psr14_events_enabled' => $this->config->isPsr14EventsEnabled(),
            'strict_validation' => $this->config->isStrictValidationEnabled(),
            'key_prefix' => $this->config->getDefaultKeyPrefix(),
            'namespace_separator' => $this->config->getNamespaceSeparator(),
        ];

        return array_merge($baseConfig, $this->overrides);
    }

    /**
     * Validate builder configuration.
     */
    private function validateConfiguration(): void
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be configured');
        }

        // Validate that all layers have valid drivers
        foreach ($this->layers as $index => $layer) {
            if (!isset($layer['driver'])) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}", 'Layer driver not specified');
            }

            if (!$this->factoryRegistry->hasDriver($layer['driver'])) {
                throw ConfigurationException::missingDriver($layer['driver']);
            }
        }

        // Validate configuration integrity
        $errors = $this->config->validate();
        if (!empty($errors)) {
            throw ConfigurationException::invalidConfiguration('validation', 'Configuration validation failed: ' . implode(', ', $errors));
        }

        $this->logger->info('Cache configuration validated successfully', [
            'layers_count' => count($this->layers),
            'serializer' => $this->serializer ? get_class($this->serializer) : 'none',
            'features' => array_keys(array_filter($this->overrides)),
        ]);
    }
}
