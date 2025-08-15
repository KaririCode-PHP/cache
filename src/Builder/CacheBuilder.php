<?php

declare(strict_types=1);

namespace KaririCode\Cache\Builder;

use KaririCode\Cache\Cache;
use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Contract\AdvancedCache;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Decorator\EncryptedCacheDecorator;
use KaririCode\Cache\Driver\LayeredDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\Expiration\ConfigurableExpirationProvider;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\HealthCheckService;
use KaririCode\Cache\Service\LockService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerFactory;
use KaririCode\Logging\LoggerRegistry;
use KaririCode\Logging\SilentLogger;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Enhanced fluent builder for creating a fully-configured Cache instance with advanced logging.
 *
 * This class follows the Builder pattern to assemble a complex Cache object with
 * sophisticated logging capabilities. It integrates with KaririCode's logging
 * infrastructure to provide comprehensive observability and monitoring.
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
    private Logger $logger;
    private ?DriverManager $driverManager = null;
    private ?LoggerRegistry $loggerRegistry = null;
    private ?LoggerFactory $loggerFactory = null;
    private ?EventDispatcherInterface $eventDispatcher = null;
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
        $this->logger = new SilentLogger();
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

    public function withLogger(Logger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Configure the builder with a LoggerRegistry for advanced logging capabilities.
     * This enables multiple specialized logging channels for the EventService.
     */
    public function withLoggerRegistry(LoggerRegistry $loggerRegistry): self
    {
        $this->loggerRegistry = $loggerRegistry;

        return $this;
    }

    /**
     * Configure the builder with a LoggerFactory to create specialized loggers.
     * This is useful when you need to create loggers on-demand with specific configurations.
     */
    public function withLoggerFactory(LoggerFactory $loggerFactory): self
    {
        $this->loggerFactory = $loggerFactory;

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
     * Builds the final Cache instance with advanced logging capabilities.
     */
    public function build(): AdvancedCache
    {
        $loggerRegistry = $this->resolveLoggerRegistry($this->logger);

        // Create enhanced EventServiceConfig based on application configuration
        $eventConfig = $this->createEventServiceConfig();

        // 1. Resolve or create core services with proper logging integration
        $validationService = $this->validationService ?? new ValidationService();
        $serializationService = $this->serializationService ?? $this->createSerializationService();

        // Create EventService with advanced logging support
        $eventService = $this->eventService ?? new EventService(
            $this->eventDispatcher,
            $loggerRegistry,
            $eventConfig
        );

        $metricsService = $this->metricsService ?? new MetricsService($this->logger);
        $expirationProvider = $this->expirationProvider ?? $this->createExpirationProvider();

        // 2. Build the central driver (which is always a LayeredDriver)
        $driver = $this->buildDriver($expirationProvider, $this->logger);

        // 3. Build services that depend on the driver
        $lockService = $this->lockService ?? new LockService($driver, $this->logger);
        $healthCheckService = $this->healthCheckService ?? new HealthCheckService(
            $driver,
            $metricsService,
            $this->logger
        );

        // 4. Assemble the main Cache facade
        $cache = new Cache(
            name: $this->storeName,
            driver: $driver,
            validator: $validationService,
            serializer: $serializationService,
            events: $eventService,
            metrics: $metricsService,
            health: $healthCheckService,
            locks: $lockService,
            logger: $this->logger
        );

        // 5. Apply decorators based on configuration
        return $this->applyDecorators($cache, $serializationService);
    }

    /**
     * Resolve or create a LoggerRegistry for advanced logging capabilities.
     */
    private function resolveLoggerRegistry(Logger $fallbackLogger): ?LoggerRegistry
    {
        // If a LoggerRegistry was explicitly provided, use it
        if (null !== $this->loggerRegistry) {
            return $this->loggerRegistry;
        }

        // If a LoggerFactory was provided, create specialized loggers
        if (null !== $this->loggerFactory) {
            return $this->createLoggerRegistryFromFactory($this->loggerFactory);
        }

        // If advanced logging is not configured, return null to use fallback
        // The EventService will handle null LoggerRegistry gracefully
        return null;
    }

    /**
     * Create a LoggerRegistry with specialized loggers using the provided factory.
     */
    private function createLoggerRegistryFromFactory(LoggerFactory $factory): LoggerRegistry
    {
        $registry = new LoggerRegistry();

        try {
            // Create specialized loggers for different cache operations
            $cacheLogger = $factory->createLogger('cache');
            $performanceLogger = $factory->createLogger('performance');
            $errorLogger = $factory->createLogger('error');
            $asyncLogger = $factory->createLogger('async');

            // Register loggers in the registry
            $registry->addLogger('cache', $cacheLogger);
            $registry->addLogger('performance', $performanceLogger);
            $registry->addLogger('error', $errorLogger);
            $registry->addLogger('async', $asyncLogger);
        } catch (\Throwable $e) {
            // If logger creation fails, log the error and return empty registry
            $this->logger?->warning('Failed to create specialized loggers', [
                'error' => $e->getMessage(),
                'fallback' => 'Using NullLogger for specialized channels',
            ]);
        }

        return $registry;
    }

    /**
     * Create EventServiceConfig based on application configuration and environment.
     */
    private function createEventServiceConfig(): EventServiceConfig
    {
        $features = $this->config->getFeatures();
        $logging = $this->config->getLogging();

        // Use environment-specific configurations
        return new EventServiceConfig(
            enabled: $features->eventsEnabled,
            logOnDispatchError: true,
            performanceLoggingEnabled: $features->metricsEnabled,
            asyncLoggingEnabled: true,
            structuredLoggingEnabled: true,
            logLevel: $logging->level ?? 'debug',
            // Custom performance thresholds based on configuration
            performanceThresholds: $this->getPerformanceThresholds(),
            // Sensitive keys based on application security requirements
            sensitiveKeys: $this->getSensitiveKeys(),
        );
    }

    /**
     * Get performance thresholds from configuration or use defaults.
     */
    private function getPerformanceThresholds(): array
    {
        // These could come from configuration files or environment variables
        return [
            'cache_hit' => (float) ($_ENV['CACHE_HIT_THRESHOLD_MS'] ?? 10.0),
            'cache_miss' => (float) ($_ENV['CACHE_MISS_THRESHOLD_MS'] ?? 5.0),
            'cache_set' => (float) ($_ENV['CACHE_SET_THRESHOLD_MS'] ?? 50.0),
            'cache_delete' => (float) ($_ENV['CACHE_DELETE_THRESHOLD_MS'] ?? 25.0),
            'cache_clear' => (float) ($_ENV['CACHE_CLEAR_THRESHOLD_MS'] ?? 500.0),
            'tag_invalidation' => (float) ($_ENV['TAG_INVALIDATION_THRESHOLD_MS'] ?? 200.0),
            'atomic_operation' => (float) ($_ENV['ATOMIC_OPERATION_THRESHOLD_MS'] ?? 100.0),
        ];
    }

    /**
     * Get sensitive keys that should be masked in logs.
     */
    private function getSensitiveKeys(): array
    {
        $defaultKeys = ['password', 'token', 'secret', 'key', 'auth', 'session'];

        // Allow additional sensitive keys from environment
        $additionalKeys = explode(',', $_ENV['CACHE_SENSITIVE_KEYS'] ?? '');
        $additionalKeys = array_filter(array_map('trim', $additionalKeys));

        return array_merge($defaultKeys, $additionalKeys);
    }

    private function createSerializationService(): SerializationService
    {
        $service = new SerializationService($this->logger);
        // In a real application, you would populate this from the config's 'serializers' section
        $service->setDefaultSerializer($this->config->getDefaultSerializer());

        return $service;
    }

    private function createExpirationProvider(): ExpirationProvider
    {
        $configDto = $this->config->getExpirationProvider();

        return ConfigurableExpirationProvider::fromConfig($configDto);
    }

    private function buildDriver(ExpirationProvider $provider, Logger $logger): LayeredDriver
    {
        $storeConfig = $this->config->getStore($this->storeName);
        $driverManager = $this->driverManager ?? new DriverManager();

        // The DriverManager needs the central serializer to pass it down to factories
        if ($this->serializationService) {
            $driverManager->setSerializer($this->serializationService);
        }

        $driverOptions = array_merge(
            $storeConfig->config,
            [
                'expiration_provider' => $provider,
                'logger' => $logger,
            ]
        );

        $driver = $driverManager->createDriver($storeConfig->driver, $driverOptions);

        if (!$driver instanceof LayeredDriver) {
            $storeSpecificConfig = $storeConfig->config;
            $layeredConfig = LayeredDriverConfig::fromConfig($storeSpecificConfig);

            $driver = new LayeredDriver([$driver], $layeredConfig, $logger);
        }

        return $driver;
    }

    private function applyDecorators(CacheContract $cache, SerializationService $serializer): AdvancedCache
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

    /**
     * Helper method to configure the builder with a complete logging setup.
     * This is a convenience method for common use cases.
     */
    public function withAdvancedLogging(
        LoggerRegistry $loggerRegistry,
        ?EventDispatcherInterface $eventDispatcher = null
    ): self {
        $this->loggerRegistry = $loggerRegistry;

        if (null !== $eventDispatcher) {
            $this->eventDispatcher = $eventDispatcher;
        }

        return $this;
    }
}
