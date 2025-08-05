<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Driver\Factory\DriverFactoryRegistry;
use KaririCode\Cache\Event\Factory\CacheEventFactory;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Item\CachePoolManager;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Operation\AdvancedCacheOperations;
use KaririCode\Cache\Operation\BasicCacheOperations;
use KaririCode\Cache\Operation\TaggedCacheOperations;
use KaririCode\Cache\Operation\TopicCacheOperations;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\CompressingSerializer;
use KaririCode\Cache\Serializer\IgbinarySerializer;
use KaririCode\Cache\Serializer\JsonSerializer;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Implements the Builder pattern for constructing complex Cache instances.
 * This class provides a fluent interface to configure and build a cache
 * instance with all its dependencies.
 */
final class CacheBuilder
{
    /** @var array<int, array{driver: string, config: array<string, mixed>, priority: int}> */
    private array $layers = [];

    private string $storeName = 'default';
    private CacheSerializer $serializer;
    private ExpirationProvider $expirationProvider;
    private LoggerInterface $logger;
    private ?StampedeProtectorContract $stampedeProtector = null;
    private ?MetricsCollectorContract $metricsCollector = null;
    private ?EventDispatcherInterface $eventDispatcher = null;

    /** @var array<string, bool> */
    private array $featureOverrides = [];

    public function __construct(
        private readonly CacheConfig $config,
        LoggerInterface $logger,
        private readonly DriverFactoryRegistry $factoryRegistry = new DriverFactoryRegistry()
    ) {
        $this->logger = $logger;
        $this->applyConfigurationDefaults();
    }

    /**
     * Configures the builder using a named store from the cache configuration.
     *
     * @param string $storeName the name of the store to use
     *
     * @throws ConfigurationException if the store is not found
     *
     * @return $this
     */
    public function configureFromStore(string $storeName): self
    {
        if (!$this->config->has("stores.{$storeName}")) {
            throw ConfigurationException::missingDriver($storeName);
        }

        $this->storeName = $storeName;
        $storeConfig = $this->config->get("stores.{$storeName}");

        // Load store-specific configuration
        if (isset($storeConfig['driver'])) {
            $this->addLayer($storeConfig['driver'], $storeConfig['config'] ?? [], 0);
        }

        return $this;
    }

    /**
     * Configures the builder using the default store from the cache configuration.
     *
     * @return $this
     */
    public function configureFromDefaultStore(): self
    {
        $defaultStore = $this->config->getDefaultStore();

        return $this->configureFromStore($defaultStore);
    }

    /**
     * Overrides the default serializer.
     *
     * @param CacheSerializer $serializer the serializer instance to use
     *
     * @return $this
     */
    public function withSerializer(CacheSerializer $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Overrides the default stampede protector.
     *
     * @param StampedeProtectorContract|null $stampedeProtector the protector instance to use
     *
     * @return $this
     */
    public function withStampedeProtector(?StampedeProtectorContract $stampedeProtector): self
    {
        $this->stampedeProtector = $stampedeProtector;

        return $this;
    }

    /**
     * Overrides the default metrics collector.
     *
     * @param MetricsCollectorContract|null $metricsCollector the collector instance to use
     *
     * @return $this
     */
    public function withMetricsCollector(?MetricsCollectorContract $metricsCollector): self
    {
        $this->metricsCollector = $metricsCollector;

        return $this;
    }

    /**
     * Overrides the default PSR-14 event dispatcher.
     *
     * @param EventDispatcherInterface|null $eventDispatcher the dispatcher instance to use
     *
     * @return $this
     */
    public function withEventDispatcher(?EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Overrides the default logger.
     *
     * @param LoggerInterface $logger the logger instance to use
     *
     * @return $this
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Enables or disables a specific feature, overriding the configuration file.
     *
     * @param string $feature The name of the feature (e.g., 'stampede_protection').
     * @param bool $enabled true to enable, false to disable
     *
     * @return $this
     */
    public function withFeature(string $feature, bool $enabled): self
    {
        $this->featureOverrides[$feature] = $enabled;

        return $this;
    }

    /**
     * Adds a custom cache layer to the builder.
     *
     * @param string $driver the name of the driver for this layer
     * @param array<string, mixed> $config the configuration for this layer's driver
     * @param int $priority the priority of this layer (higher numbers are checked first)
     *
     * @throws ConfigurationException if the driver is not registered
     *
     * @return $this
     */
    public function addCustomLayer(string $driver, array $config, int $priority): self
    {
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
     * Builds the final Cache instance based on the provided configuration.
     *
     * @throws ConfigurationException if the configuration is invalid or a layer fails to build
     */
    public function build(): CacheContract
    {
        $this->validate();

        $layeredCache = $this->buildLayeredCache();
        $logger = $this->logger ?? new NullLogger();

        $basic = new BasicCacheOperations(
            layeredCache: $layeredCache,
            expirationProvider: $this->expirationProvider,
            serializer: $this->serializer,
            logger: $logger,
            eventDispatcher: $this->eventDispatcher
        );

        $tagged = new TaggedCacheOperations(
            layeredCache: $layeredCache,
            expirationProvider: $this->expirationProvider,
            logger: $logger,
            eventDispatcher: $this->eventDispatcher
        );

        $topic = new TopicCacheOperations(
            layeredCache: $layeredCache,
            expirationProvider: $this->expirationProvider,
            logger: $logger,
            eventDispatcher: $this->eventDispatcher
        );

        $advanced = new AdvancedCacheOperations(
            layeredCache: $layeredCache,
            expirationProvider: $this->expirationProvider,
            serializer: $this->serializer,
            logger: $logger,
            eventDispatcher: $this->eventDispatcher,
            config: $this->config
        );

        return new Cache(
            basic: $basic,
            tagged: $tagged,
            topic: $topic,
            advanced: $advanced,
            name: $this->storeName
        );
    }

    /**
     * Sets the expiration provider for the cache.
     *
     * @param ExpirationProvider $provider the expiration provider to use
     *
     * @return $this
     */
    public function setExpirationProvider(ExpirationProvider $provider): self
    {
        $this->expirationProvider = $provider;

        return $this;
    }

    /**
     * Sets the serializer for the cache.
     *
     * @param CacheSerializer $serializer the serializer to use
     *
     * @return $this
     */
    public function setSerializer(CacheSerializer $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Sets the logger for the cache.
     *
     * @param LoggerInterface $logger the logger to use
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Sets the event dispatcher for the cache.
     *
     * @param EventDispatcherInterface $eventDispatcher the event dispatcher to use
     *
     * @return $this
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Sets the stampede protector for the cache.
     *
     * @param StampedeProtectorContract $protector the stampede protector to use
     *
     * @return $this
     */
    public function setStampedeProtector(StampedeProtectorContract $protector): self
    {
        $this->stampedeProtector = $protector;

        return $this;
    }

    /**
     * Sets the metrics collector for the cache.
     *
     * @param MetricsCollectorContract $collector the metrics collector to use
     *
     * @return $this
     */
    public function setMetricsCollector(MetricsCollectorContract $collector): self
    {
        $this->metricsCollector = $collector;

        return $this;
    }

    /**
     * Adds a layer to the cache builder.
     *
     * @param string $driver the name of the driver for this layer
     * @param array<string, mixed> $config the configuration for this layer's driver
     * @param int $priority the priority of this layer (higher numbers are checked first)
     *
     * @throws ConfigurationException if the driver is not registered
     *
     * @return $this
     */
    public function addLayer(string $driver, array $config = [], int $priority = 0): self
    {
        if (empty($driver)) {
            throw ConfigurationException::invalidConfiguration('driver', 'Driver name cannot be empty');
        }

        $this->layers[] = [
            'driver' => $driver,
            'config' => $config,
            'priority' => $priority,
        ];

        return $this;
    }

    private function setupFromStoreConfig(StoreConfig $storeConfig): void
    {
        $driver = $storeConfig->driver;
        $config = $storeConfig->config;

        if ('layered' === $driver) {
            $this->setupLayeredCache($storeConfig);
        } else {
            $this->addCustomLayer($driver, $config, 50);
        }
    }

    private function setupLayeredCache(StoreConfig $storeConfig): void
    {
        foreach ($storeConfig->layers as $layerData) {
            $this->addCustomLayer(
                $layerData['driver'],
                $layerData['config'],
                $layerData['priority']
            );
        }
    }

    private function applyConfigurationDefaults(): void
    {
        if (!isset($this->serializer)) {
            $this->serializer = $this->createSerializerFromConfig();
        }

        $baseFeatures = $this->config->getFeatureConfig()->toArray();

        $effectiveFeaturesArray = array_merge($baseFeatures, $this->featureOverrides);

        $effectiveFeatures = FeatureConfig::fromArray($effectiveFeaturesArray);
        $this->applyFeatureBasedDefaults($effectiveFeatures);
    }

    private function applyFeatureBasedDefaults(FeatureConfig $features): void
    {
        if ($features->stampedeProtection && null === $this->stampedeProtector) {
            $this->stampedeProtector = new StampedeProtector();
        }

        if ($features->metricsEnabled && null === $this->metricsCollector) {
            $this->metricsCollector = new MetricsCollector();
        }

        if ($features->eventsEnabled && null === $this->eventDispatcher) {
            $eventFactory = new CacheEventFactory($this->config);
            $eventSystem = $eventFactory->createCompleteEventSystem();
            $this->eventDispatcher = $eventSystem->eventDispatcher;
        }
    }

    private function createSerializerFromConfig(): CacheSerializer
    {
        $serializerType = $this->config->getSerializer();

        return match ($serializerType) {
            'json' => new JsonSerializer(),
            'igbinary' => extension_loaded('igbinary') ? new IgbinarySerializer() : new NativeSerializer(),
            'compressed' => new CompressingSerializer(new NativeSerializer()),
            'compressed_igbinary' => new CompressingSerializer(
                extension_loaded('igbinary') ? new IgbinarySerializer() : new NativeSerializer()
            ),
            default => new NativeSerializer(),
        };
    }

    private function buildLayeredCache(): LayeredCache
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'No cache layers have been configured.');
        }

        $layeredCache = new LayeredCacheManager($this->logger);
        usort($this->layers, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        foreach ($this->layers as $layerConfig) {
            try {
                $driver = $this->factoryRegistry->createDriver(
                    $layerConfig['driver'],
                    $layerConfig['config'],
                    $this->serializer
                );

                $pool = new CachePoolManager($driver, new CacheKeyValidator());
                $layeredCache->addLayer($pool, $layerConfig['priority']);

                $this->logger->debug('Cache layer created', [
                    'driver' => $layerConfig['driver'],
                    'priority' => $layerConfig['priority'],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to create cache layer', [
                    'driver' => $layerConfig['driver'],
                    'error' => $e->getMessage(),
                ]);
                throw ConfigurationException::invalidConfiguration($layerConfig['driver'], "Failed to create cache layer: {$e->getMessage()}");
            }
        }

        return $layeredCache;
    }

    private function validate(): void
    {
        if (empty($this->storeName)) {
            throw ConfigurationException::invalidConfiguration('storeName', 'Store name cannot be empty');
        }

        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be configured');
        }

        foreach ($this->layers as $layer) {
            if (!$this->factoryRegistry->hasDriver($layer['driver'])) {
                throw ConfigurationException::missingDriver($layer['driver']);
            }
        }

        $errors = $this->config->validate();
        if (!empty($errors)) {
            throw ConfigurationException::invalidConfiguration('validation', 'Configuration validation failed: ' . implode(', ', $errors));
        }

        $this->logger->info('Cache configuration validated successfully', [
            'layers_count' => count($this->layers),
            'serializer' => get_class($this->serializer),
        ]);
    }
}
