<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Decorator\EncryptedCacheDecorator;
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
 * Fluent builder for creating a fully-configured Cache instance.
 *
 * This class follows the Builder pattern to assemble a complex Cache object.
 * Its sole responsibility is to gather all necessary components (services,
 * configuration, drivers) and inject them into the Cache facade. It does not
 * create dependencies itself, adhering to Dependency Injection principles.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class CacheBuilder
{
    private string $storeName = 'default';
    private ?CacheConfig $config = null;
    private ?DriverManager $driverManager = null;
    private ?LoggerInterface $logger = null;
    private ?EventDispatcherInterface $eventDispatcher = null;

    // Services to be injected
    private ?ValidationService $validationService = null;
    private ?SerializationService $serializationService = null;
    private ?MetricsService $metricsService = null;
    private ?EventService $eventService = null;
    private ?HealthCheckService $healthCheckService = null;
    private ?LockService $lockService = null;
    private ?ExpirationProvider $expirationProvider = null;

    /**
     * @param CacheConfig $config the central cache configuration object
     */
    public function __construct(CacheConfig $config)
    {
        $this->config = $config;
    }

    public static function fromConfig(CacheConfig $config): self
    {
        return new self($config);
    }

    // --- Fluent Configuration Methods ---

    public function forStore(string $storeName): self
    {
        if (!$this->config->has($storeName)) {
            throw ConfigurationException::missingStore($storeName);
        }
        $this->storeName = $storeName;

        return $this;
    }

    public function withDriverManager(DriverManager $driverManager): self
    {
        $this->driverManager = $driverManager;

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

    // --- Fluent Service Injection Methods ---

    public function withValidationService(ValidationService $service): self
    {
        $this->validationService = $service;

        return $this;
    }

    public function withSerializationService(SerializationService $service): self
    {
        $this->serializationService = $service;

        return $this;
    }

    public function withMetricsService(MetricsService $service): self
    {
        $this->metricsService = $service;

        return $this;
    }

    public function withEventService(EventService $service): self
    {
        $this->eventService = $service;

        return $this;
    }

    public function withHealthCheckService(HealthCheckService $service): self
    {
        $this->healthCheckService = $service;

        return $this;
    }

    public function withLockService(LockService $service): self
    {
        $this->lockService = $service;

        return $this;
    }

    public function withExpirationProvider(ExpirationProvider $provider): self
    {
        $this->expirationProvider = $provider;

        return $this;
    }

    /**
     * Builds the final Cache instance.
     *
     * This method orchestrates the creation of all dependencies if they haven't
     * been injected, builds the layered cache manager, and assembles the final
     * Cache facade with all its services.
     *
     * @return Cache the fully configured Cache instance
     */
    public function build(): Cache
    {
        $logger = $this->logger ?? new NullLogger();

        // Resolve or create services. In a DI container context, these would be resolved.
        $validationService = $this->validationService ?? new ValidationService();
        $serializationService = $this->serializationService ?? $this->createSerializationService();
        $eventService = $this->eventService ?? new EventService($this->eventDispatcher, $logger);
        $metricsService = $this->metricsService ?? new MetricsService($logger);
        $expirationProvider = $this->expirationProvider ?? $this->createExpirationProvider();

        // Build the core layered cache manager
        $layeredCacheManager = $this->buildLayeredCacheManager($expirationProvider, $logger);

        // Build services that depend on the cache manager
        $lockService = $this->lockService ?? new LockService($layeredCacheManager, $logger);
        $healthCheckService = $this->healthCheckService ?? new HealthCheckService($layeredCacheManager, $metricsService, $logger);

        $cache = new Cache(
            name: $this->storeName,
            layeredCache: $layeredCacheManager,
            validator: $validationService,
            serializer: $serializationService,
            events: $eventService,
            metrics: $metricsService,
            health: $healthCheckService,
            locks: $lockService,
            logger: $logger
        );

        return $this->applyDecorators($cache, $serializationService);
    }

    private function createSerializationService(): SerializationService
    {
        $service = new SerializationService($this->logger ?? new NullLogger());
        // Logic to instantiate serializers from config...
        $service->setDefaultSerializer($this->config->getDefaultSerializer());

        return $service;
    }

    private function createExpirationProvider(): ExpirationProvider
    {
        $configDto = $this->config->getExpirationProvider();

        return ConfigurableExpirationProvider::fromConfig($configDto);
    }

    private function buildLayeredCacheManager(ExpirationProvider $provider, LoggerInterface $logger): LayeredCacheManager
    {
        $storeConfig = $this->config->getStore($this->storeName);
        $driverManager = $this->driverManager ?? new DriverManager();

        $builder = (new LayeredCacheBuilder())
            ->withExpirationProvider($provider)
            ->withDriverManager($driverManager)
            ->withLogger($logger);

        if ($storeConfig->isLayered()) {
            $builder->withLayers($storeConfig->getLayers());
        } else {
            $builder->withLayers([
                [
                    'driver' => $storeConfig->getDriver(),
                    'config' => $storeConfig->getConfig(),
                    'priority' => 100,
                ],
            ]);
        }

        return $builder->build();
    }

    private function applyDecorators(Cache $cache, SerializationService $serializer): Cache
    {
        $features = $this->config->getFeatures();

        if ($features->encryptionEnabled) {
            $key = $features->encryptionKey ?? null;
            if (null === $key) {
                throw ConfigurationException::missingConfiguration('Encryption key is required but not provided.');
            }
            // The decorator wraps the fully constructed cache instance.
            $cache = new EncryptedCacheDecorator($cache, $serializer, $key);
        }

        return $cache;
    }
}
