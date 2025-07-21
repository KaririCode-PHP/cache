<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Contract\CacheEventListener as CacheEventListenerContract;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\CacheValidator;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
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
    /** @var array<int, array{driver: string, config: array<string, mixed>, priority: int}> */
    private array $layers = [];

    private ?CacheSerializer $serializer = null;
    private ?StampedeProtectorContract $stampedeProtector = null;
    private ?MetricsCollectorContract $metricsCollector = null;
    private ?CacheEventListenerContract $eventListener = null;
    private ?CacheValidator $keyValidator = null;
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
    public function withKeyValidator(?CacheValidator $keyValidator): self
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
     * Setup builder from store configuration.
     *
     * @param StoreConfig $storeConfig // CORREÇÃO: O tipo do parâmetro foi atualizado para o DTO
     */
    private function setupFromStoreConfig(StoreConfig $storeConfig): void
    {
        // CORREÇÃO: Acessamos as propriedades do objeto em vez de chaves de array
        $driver = $storeConfig->driver;
        $config = $storeConfig->config;

        switch ($driver) {
            case 'layered':
                $this->setupLayeredCache($storeConfig); // Passamos o objeto
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

    /**
     * Setup layered cache from configuration.
     */
    private function setupLayeredCache(StoreConfig $storeConfig): void
    {
        foreach ($storeConfig->layers as $layerConfig) {
            $this->addCustomLayer(
                $layerConfig['driver'],
                $layerConfig['config'],
                $layerConfig['priority']
            );
        }
    }

    /**
     * Setup single layer cache.
     *
     * @param array<string, mixed> $config
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
        if (!$this->serializer) {
            $this->serializer = $this->createSerializerFromConfig();
        }

        if ($this->config->isStampedeProtectionEnabled() && !$this->stampedeProtector) {
            $this->stampedeProtector = new StampedeProtector();
        }

        if ($this->config->isMetricsEnabled() && !$this->metricsCollector) {
            $this->metricsCollector = new MetricsCollector();
        }

        if ($this->config->isEventsEnabled() && !$this->eventListener) {
            $this->eventListener = new DefaultCacheEventListener($this->config->getMaxEvents());
        }

        if ($this->config->isPsr14EventsEnabled() && !$this->eventDispatcher) {
            $eventSystem = CacheEventFactory::createCompleteEventSystem();
            $this->eventDispatcher = $eventSystem->eventDispatcher;
        }

        if ($this->config->isStrictValidationEnabled() && !$this->keyValidator) {
            $this->keyValidator = new CacheKeyValidator();
        }
    }

    /**
     * Create serializer based on configuration.
     *
     * @return IgbinarySerializer|JsonSerializer|CompressingSerializer|NativeSerializer
     */
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

                $pool = new CachePoolManager($driver, $this->keyValidator ?? new CacheKeyValidator());
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
     *
     * @return array<string, mixed>
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
            'features' => array_keys(array_filter($this->overrides)),
        ]);
    }
}
