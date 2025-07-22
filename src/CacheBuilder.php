<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Driver\Factory\DriverFactoryRegistry;
use KaririCode\Cache\Event\Factory\CacheEventFactory;
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
use Psr\Log\LoggerInterface;

/**
 * Implements the Builder pattern for constructing complex Cache instances.
 * This class provides a fluent interface to configure and build a cache
 * instance with all its dependencies.
 */
final class CacheBuilder
{
    /** @var array<int, array{driver: string, config: array<string, mixed>, priority: int}> */
    private array $layers = [];

    private CacheSerializer $serializer;
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
        $storeConfig = $this->config->getStore($storeName);
        $this->setupFromStoreConfig($storeConfig);

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
    public function build(): Cache
    {
        $this->validateConfiguration();
        $layeredCache = $this->buildLayeredCache();

        return new Cache(
            $layeredCache,
            $this->serializer,
            $this->logger,
            $this->eventDispatcher,
            $this->buildEffectiveConfig()
        );
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

        $features = $this->config->getFeatureConfig();
        $this->applyFeatureBasedDefaults($features);
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

    /**
     * @return array<string, mixed>
     */
    private function buildEffectiveConfig(): array
    {
        $features = $this->config->getFeatureConfig()->toArray();
        $mergedFeatures = array_merge($features, $this->featureOverrides);

        return [
            'name' => $this->config->getDefaultStore(),
            'features' => $mergedFeatures,
            'stampede_protection' => [
                'enabled' => $mergedFeatures['stampede_protection'],
                'lock_timeout' => $this->config->get('stampede_lock_timeout', 10),
                'lock_retry_delay' => $this->config->get('stampede_lock_retry_delay', 100),
            ],
        ];
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

    private function validateConfiguration(): void
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be configured.');
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
