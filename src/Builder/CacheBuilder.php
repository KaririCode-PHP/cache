<?php

declare(strict_types=1);

namespace KaririCode\Cache\Builder;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Decorator\EncryptedCacheDecorator;
use KaririCode\Cache\Driver\LayeredDriver;
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

    public function __construct(private readonly CacheConfig $config)
    {
        $this->storeName = $config->getDefaultStore();
    }

    public static function fromConfig(CacheConfig $config): self
    {
        return new self($config);
    }

    // --- Fluent Configuration & Injection Methods ---

    public function forStore(string $storeName): self
    {
        if (!$this->config->hasStore($storeName)) {
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
     */
    public function build(): Cache
    {
        $logger = $this->logger ?? new NullLogger();

        // 1. Resolve or create core services.
        $validationService = $this->validationService ?? new ValidationService();
        $serializationService = $this->serializationService ?? $this->createSerializationService();
        $eventService = $this->eventService ?? new EventService($this->eventDispatcher, $logger);
        $metricsService = $this->metricsService ?? new MetricsService($logger);
        $expirationProvider = $this->expirationProvider ?? $this->createExpirationProvider();

        // 2. Build the central driver (which is always a LayeredDriver).
        $driver = $this->buildDriver($expirationProvider, $logger);

        // 3. Build services that depend on the driver.
        $lockService = $this->lockService ?? new LockService($driver, $logger);
        $healthCheckService = $this->healthCheckService ?? new HealthCheckService($driver, $metricsService, $logger);

        // 4. Assemble the main Cache facade.
        $cache = new Cache(
            name: $this->storeName,
            driver: $driver,
            validator: $validationService,
            serializer: $serializationService,
            events: $eventService,
            metrics: $metricsService,
            health: $healthCheckService,
            locks: $lockService,
            logger: $logger
        );

        // 5. Apply decorators based on configuration.
        return $this->applyDecorators($cache, $serializationService);
    }

    private function createSerializationService(): SerializationService
    {
        $service = new SerializationService($this->logger ?? new NullLogger());
        // In a real application, you would populate this from the config's 'serializers' section.
        $service->setDefaultSerializer($this->config->getDefaultSerializer());

        return $service;
    }

    private function createExpirationProvider(): ExpirationProvider
    {
        $configDto = $this->config->getExpirationProvider();

        return ConfigurableExpirationProvider::fromConfig($configDto);
    }

    private function buildDriver(ExpirationProvider $provider, LoggerInterface $logger): LayeredDriver
    {
        $storeConfig = $this->config->getStore($this->storeName);
        $driverManager = $this->driverManager ?? new DriverManager();

        // The DriverManager needs the central serializer to pass it down to factories.
        if ($this->serializationService) {
            $driverManager->setSerializer($this->serializationService);
        }

        // The driver for a specific store is created here.
        // The DriverManager will use the correct factory (e.g., LayeredDriverFactory).
        $driver = $driverManager->createDriver($storeConfig->getDriver(), [
            'store_config' => $storeConfig,
            'expiration_provider' => $provider,
            'logger' => $logger,
        ]);

        if (!$driver instanceof LayeredDriver) {
            // In our architecture, even single-driver stores are wrapped in a LayeredDriver
            // with one layer for consistency. The DriverManager/Factory should handle this.
            // If it doesn't, we can wrap it here as a safeguard.
            $layeredConfig = new LayeredDriverConfig([]);
            $driver = new LayeredDriver([$driver], $layeredConfig, $logger);
        }

        return $driver;
    }

    private function applyDecorators(Cache $cache, SerializationService $serializer): Cache
    {
        $features = $this->config->getFeatures();

        if ($features->encryptionEnabled) {
            $key = $features->encryptionKey;
            if (empty($key)) {
                throw ConfigurationException::missingConfiguration('Encryption key is required but not provided.');
            }
            $cache = new EncryptedCacheDecorator($cache, $serializer, $key);
        }

        return $cache;
    }
}
