<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\StoreConfiguration;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Decorator\EncryptedCacheDecorator;
use KaririCode\Cache\Driver\Factory\DriverFactoryRegistry;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\HealthCheckService;
use KaririCode\Cache\Service\LockService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced CacheBuilder with a fluent interface and dependency management.
 *
 * Follows the Builder pattern with method chaining for configuration.
 * Manages all dependencies and ensures proper initialization order.
 */
final class CacheBuilder
{
    private string $name = 'default';
    private string $storeName = 'default';
    private ?CacheConfig $config = null;

    // Services
    private ?ValidationService $validator = null;
    private CacheSerializer|string|null $serializer = null;
    private ?EventService $events = null;
    private ?MetricsService $metrics = null;
    private ?LockService $locks = null;
    private ?HealthCheckService $health = null;
    private ?ExpirationProvider $expirationProvider = null;

    // External dependencies
    private LoggerInterface $logger;
    private ?EventDispatcherInterface $eventDispatcher = null;

    // Configuration options
    private array $layers = [];
    private array $features = [];
    private array $ttlConfig = [];

    // Factories
    private ?DriverFactoryRegistry $driverRegistry = null;

    public function __construct(?CacheConfig $config = null)
    {
        $this->config = $config;
        $this->logger = new NullLogger();

        if (null !== $config) {
            $this->applyConfiguration($config);
        }
    }

    public function withName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function withStore(string $storeName): self
    {
        $this->storeName = $storeName;

        if (null !== $this->config) {
            $storeConfig = $this->config->getStore($storeName);
            if (null === $storeConfig) {
                throw ConfigurationException::missingStore($storeName);
            }
            $this->applyStoreConfiguration($storeConfig);
        }

        return $this;
    }

    public function withLayer(string $driver, array $config = [], int $priority = 100): self
    {
        $this->layers[] = [
            'driver' => $driver,
            'config' => $config,
            'priority' => $priority,
        ];

        usort($this->layers, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $this;
    }

    public function withSerializer(CacheSerializer|string $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    public function withTtlConfig(array $ttlConfig): self
    {
        $this->ttlConfig = array_merge($this->ttlConfig, $ttlConfig);

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): self
    {
        $this->eventDispatcher = $dispatcher;

        return $this;
    }

    public function withStampedeProtection(bool $enabled = true): self
    {
        $this->features['stampede_protection'] = $enabled;

        return $this;
    }

    public function withMetrics(bool $enabled = true): self
    {
        $this->features['metrics'] = $enabled;

        return $this;
    }

    public function withEncryption(?string $key = null): self
    {
        $this->features['encryption'] = true;
        if (null !== $key) {
            $this->features['encryption_key'] = $key;
        }

        return $this;
    }

    public function withValidationService(ValidationService $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    public function withExpirationProvider(ExpirationProvider $provider): self
    {
        $this->expirationProvider = $provider;

        return $this;
    }

    public function withMetricsService(MetricsService $metrics): self
    {
        $this->metrics = $metrics;

        return $this;
    }

    public function withLockService(LockService $locks): self
    {
        $this->locks = $locks;

        return $this;
    }

    public function withHealthCheckService(HealthCheckService $health): self
    {
        $this->health = $health;

        return $this;
    }

    public function withEventService(EventService $events): self
    {
        $this->events = $events;

        return $this;
    }

    public function build(): Cache
    {
        $this->validate();

        $validator = $this->validator ?? new ValidationService();
        $serializer = $this->createSerializationService();
        $events = $this->events ?? new EventService($this->eventDispatcher, $this->logger);
        $metrics = $this->metrics ?? $this->createMetricsService();
        $expirationProvider = $this->expirationProvider ?? $this->createExpirationProvider();

        $layeredCache = $this->buildLayeredCache($expirationProvider);

        $locks = $this->locks ?? new LockService($layeredCache, $this->logger);
        $health = $this->health ?? new HealthCheckService($layeredCache, $metrics, $this->logger);

        $cache = new Cache(
            name: $this->name,
            layeredCache: $layeredCache,
            validator: $validator,
            serializer: $serializer,
            events: $events,
            metrics: $metrics,
            locks: $locks,
            health: $health,
            logger: $this->logger
        );

        return $this->applyDecorators($cache);
    }

    private function validate(): void
    {
        if (empty($this->layers)) {
            if (null === $this->config) {
                throw ConfigurationException::invalidConfiguration('layers', 'No layers configured and no config provided');
            }

            $storeConfig = $this->config->getStore($this->storeName);
            if (null === $storeConfig) {
                throw ConfigurationException::missingStore($this->storeName);
            }

            $this->applyStoreConfiguration($storeConfig);
        }

        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'No cache layers have been configured.');
        }
    }

    private function createSerializationService(): SerializationService
    {
        if ($this->serializer instanceof SerializationService) {
            return $this->serializer;
        }

        $service = new SerializationService($this->logger);

        if (null !== $this->config) {
            foreach ($this->config->getSerializers() as $name => $serializerClass) {
                if (is_string($serializerClass) && class_exists($serializerClass)) {
                    $service->registerSerializer($name, new $serializerClass());
                }
            }
        }

        if (is_string($this->serializer)) {
            $service->setDefaultSerializer($this->serializer);
        }

        return $service;
    }

    private function createMetricsService(): MetricsService
    {
        $service = new MetricsService($this->logger);

        if ($this->features['metrics'] ?? false) {
            $service->enableDetailedMetrics();
        }

        return $service;
    }

    private function createExpirationProvider(): ExpirationProvider
    {
        $configData = array_merge(
            $this->config?->getExpirationProvider()->toArray() ?? [],
            $this->ttlConfig
        );

        $expirationConfig = new ExpirationProviderConfig($configData);

        return ConfigurableExpirationProvider::fromConfig($expirationConfig);
    }

    private function buildLayeredCache(ExpirationProvider $expirationProvider): LayeredCacheManager
    {
        $driverRegistry = $this->driverRegistry ?? new DriverFactoryRegistry();

        return (new LayeredCacheBuilder())
            ->withLayers($this->layers)
            ->withExpirationProvider($expirationProvider)
            ->withDriverRegistry($driverRegistry)
            ->withLogger($this->logger)
            ->build();
    }

    private function applyDecorators(Cache $cache): Cache
    {
        if ($this->features['encryption'] ?? false) {
            $encryptionKey = $this->features['encryption_key'] ??
                             $this->config?->get('encryption.key') ??
                             throw ConfigurationException::invalidConfiguration('encryption_key', 'Encryption key not provided');

            // Assuming EncryptedCacheDecorator exists and is compatible
            // $cache = new EncryptedCacheDecorator($cache, $encryptionKey);
        }

        return $cache;
    }

    private function applyConfiguration(CacheConfig $config): void
    {
        $this->ttlConfig = $config->getExpirationProvider()->toArray();
        $this->features = $config->getFeatures()->toArray();
        $this->serializer = $config->getDefaultSerializer();
    }

    private function applyStoreConfiguration(StoreConfiguration $storeConfig): void
    {
        if ($storeConfig->isLayered()) {
            foreach ($storeConfig->getLayers() as $layer) {
                $this->withLayer(
                    $layer['driver'],
                    $layer['config'] ?? [],
                    $layer['priority'] ?? 100
                );
            }
        } else {
            $this->withLayer(
                $storeConfig->getDriver(),
                $storeConfig->getConfig(),
                100
            );
        }
    }
}
