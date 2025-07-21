<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\LayerConfig;
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
 * CacheBuilder with proper dependency injection following SOLID principles.
 *
 * This builder receives all dependencies injected and acts as a factory
 * for Cache instances based on configuration.
 */
final class CacheBuilder
{
    /** @var array<int, array{driver: string, config: array<string, mixed>, priority: int}> */
    private array $layers = [];

    private ?CacheSerializer $serializer = null;
    private ?StampedeProtectorContract $stampedeProtector = null;
    private ?MetricsCollectorContract $metricsCollector = null;
    private ?EventDispatcherInterface $eventDispatcher = null;

    /** @var array<string, bool> */
    private array $overrides = [];

    public function __construct(
        private CacheConfig $config,
        private LoggerInterface $logger,
        private DriverFactoryRegistry $factoryRegistry = new DriverFactoryRegistry()
    ) {
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
    public function withSerializer(CacheSerializer $serializer): self
    {
        $this->serializer = $serializer;

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
     * Override PSR-14 event dispatcher (dependency injection).
     */
    public function withEventDispatcher(?EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

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

        // Instantiate Cache with the new, simpler constructor signature.
        return new Cache(
            $layeredCache,
            $this->serializer,
            $this->stampedeProtector,
            $this->metricsCollector,
            $this->logger,
            $this->eventDispatcher,
            $this->buildEffectiveConfig()
        );
    }

    private function setupFromStoreConfig(StoreConfig $storeConfig): void
    {
        $driver = $storeConfig->driver;
        $config = $storeConfig->config;

        switch ($driver) {
            case 'layered':
                $this->setupLayeredCache($storeConfig);
                break;
            case 'redis':
            case 'memcached':
            case 'filesystem':
            case 'apcu':
            case 'array':
                $this->setupSingleLayerCache($driver, $config);
                break;
            default:
                throw ConfigurationException::missingDriver($driver);
        }
    }

    private function setupLayeredCache(StoreConfig $storeConfig): void
    {
        foreach ($storeConfig->layers as $layerData) {
            $layerConfig = new LayerConfig(
                driver: $layerData['driver'],
                config: $layerData['config'],
                priority: $layerData['priority']
            );
            $this->addCustomLayer(
                $layerConfig->driver,
                $layerConfig->config,
                $layerConfig->priority
            );
        }
    }

    /** @param array<string, mixed> $config */
    private function setupSingleLayerCache(string $driver, array $config): void
    {
        $this->addCustomLayer($driver, $config, 50);
    }

    private function applyConfigurationDefaults(): void
    {
        if (!$this->serializer) {
            $this->serializer = $this->createSerializerFromConfig();
        }

        $features = $this->config->getFeatureConfig();
        $this->applyFeatureBasedDefaults($features);
    }

    private function applyFeatureBasedDefaults(FeatureConfig $features): void
    {
        if ($features->stampedeProtection && !$this->stampedeProtector) {
            $this->stampedeProtector = new StampedeProtector();
        }

        if ($features->metricsEnabled && !$this->metricsCollector) {
            $this->metricsCollector = new MetricsCollector();
        }

        // The event listener is part of the PSR-14 event system, no longer created here.
        if ($features->psr14EventsEnabled && !$this->eventDispatcher) {
            $eventFactory = new CacheEventFactory($this->config);
            $eventSystem = $eventFactory->createCompleteEventSystem();
            $this->eventDispatcher = $eventSystem->eventDispatcher;
        }
    }

    /** @return array<string, mixed> */
    private function buildEffectiveConfig(): array
    {
        $features = $this->config->getFeatureConfig();
        $baseConfig = [
            'default_ttl' => $this->config->getDefaultTtl(),
            'key_prefix' => $this->config->getDefaultKeyPrefix(),
            'namespace_separator' => $this->config->getNamespaceSeparator(),
        ];
        $baseConfig = array_merge($baseConfig, $features->toArray());

        return array_merge($baseConfig, $this->overrides);
    }

    private function createSerializerFromConfig(): CacheSerializer
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

    private function buildLayeredCache(): LayeredCache
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'No cache layers configured.');
        }

        $layeredCache = new LayeredCacheManager($this->logger);
        usort($this->layers, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        foreach ($this->layers as $layerConfig) {
            try {
                if (!$this->factoryRegistry->hasDriver($layerConfig['driver'])) {
                    throw ConfigurationException::missingDriver($layerConfig['driver']);
                }

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
                    'config_keys' => array_keys($layerConfig['config']),
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
            'serializer' => $this->serializer ? get_class($this->serializer) : 'none',
        ]);
    }
}
