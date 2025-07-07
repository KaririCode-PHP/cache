<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\CacheKeyValidator as CacheKeyValidatorContract;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Event\CacheEventListener as DefaultEventListener;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Modern cache builder with fluent API, intelligent defaults, and integrated event/validation system.
 */
final class CacheBuilder
{
    private array $layers = [];
    private ?Serializer $serializer = null;
    private ?StampedeProtectorContract $stampedeProtector = null;
    private ?MetricsCollectorContract $metricsCollector = null;
    private ?LoggerInterface $logger = null;
    private ?CacheEventListener $eventListener = null;
    private ?CacheKeyValidatorContract $keyValidator = null;
    private array $config = [];
    private DriverManager $driverManager;

    public function __construct()
    {
        $this->driverManager = new DriverManager();
        $this->config = $this->getDefaultConfig();
    }

    /**
     * Create a new cache builder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a cache layer with specific driver and configuration.
     */
    public function addLayer(string $driver, array $config = [], int $priority = 100): self
    {
        $this->layers[] = [
            'driver' => $driver,
            'config' => $config,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * Add Redis layer with optimized configuration.
     */
    public function withRedis(array $config = [], int $priority = 10): self
    {
        $defaultConfig = [
            'host' => 'localhost',
            'port' => 6379,
            'timeout' => 2.0,
            'persistent' => true,
            'compression' => true,
        ];

        return $this->addLayer('redis', array_merge($defaultConfig, $config), $priority);
    }

    /**
     * Add Memcached layer with optimized configuration.
     */
    public function withMemcached(array $config = [], int $priority = 20): self
    {
        $defaultConfig = [
            'servers' => [['localhost', 11211]],
            'compression' => true,
            'serializer' => 'igbinary',
        ];

        return $this->addLayer('memcached', array_merge($defaultConfig, $config), $priority);
    }

    /**
     * Add APCu layer for ultra-fast local caching.
     */
    public function withApcu(int $priority = 5): self
    {
        return $this->addLayer('apcu', [], $priority);
    }

    /**
     * Add filesystem layer (typically used as fallback).
     */
    public function withFilesystem(?string $directory = null, int $priority = 90): self
    {
        $config = [
            'directory' => $directory ?? sys_get_temp_dir() . '/kariricode-cache',
        ];

        return $this->addLayer('filesystem', $config, $priority);
    }

    /**
     * Set custom serializer.
     */
    public function withSerializer(Serializer $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Enable/disable stampede protection.
     */
    public function withStampedeProtection(bool $enabled = true, array $config = []): self
    {
        if ($enabled) {
            $this->stampedeProtector = new StampedeProtector();
        } else {
            $this->stampedeProtector = null;
        }

        return $this;
    }

    /**
     * Enable metrics collection.
     */
    public function withMetrics(bool $enabled = true): self
    {
        if ($enabled) {
            $this->metricsCollector = new MetricsCollector();
        } else {
            $this->metricsCollector = null;
        }

        return $this;
    }

    /**
     * Set logger for debugging and monitoring.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Enable event listening with default event listener.
     */
    public function withEventLogging(bool $enabled = true): self
    {
        if ($enabled) {
            $this->eventListener = new DefaultEventListener();
        } else {
            $this->eventListener = null;
        }

        return $this;
    }

    /**
     * Set custom event listener.
     */
    public function withEventListener(CacheEventListener $eventListener): self
    {
        $this->eventListener = $eventListener;

        return $this;
    }

    /**
     * Enable comprehensive event and metrics collection.
     */
    public function withFullObservability(bool $enabled = true): self
    {
        if ($enabled) {
            $this->withMetrics(true);
            $this->withEventLogging(true);
        } else {
            $this->withMetrics(false);
            $this->withEventLogging(false);
        }

        return $this;
    }

    /**
     * Set custom key validator.
     */
    public function withKeyValidator(CacheKeyValidator $keyValidator): self
    {
        $this->keyValidator = $keyValidator;

        return $this;
    }

    /**
     * Enable strict key validation (default validator with strict rules).
     */
    public function withStrictKeyValidation(bool $enabled = true): self
    {
        if ($enabled) {
            $this->keyValidator = new CacheKeyValidator();
        } else {
            $this->keyValidator = null;
        }

        return $this;
    }

    /**
     * Set configuration options.
     */
    public function withConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * Enable sliding expiration globally.
     */
    public function withSlidingExpiration(bool $enabled = true): self
    {
        $this->config['sliding_expiration'] = $enabled;

        return $this;
    }

    /**
     * Set default TTL.
     */
    public function withDefaultTtl(int $ttl): self
    {
        $this->config['default_ttl'] = $ttl;

        return $this;
    }

    /**
     * Build the cache instance with all configured components.
     */
    public function build(): Cache
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be configured');
        }

        $layeredCache = $this->buildLayeredCache();

        return new Cache(
            $layeredCache,
            $this->serializer ?? new NativeSerializer(),
            $this->stampedeProtector ?? new StampedeProtector(),
            $this->metricsCollector ?? new MetricsCollector(),
            $this->logger ?? new NullLogger(),
            $this->eventListener, // Can be null - events are optional
            $this->keyValidator ?? new CacheKeyValidator(), // Always provide validator
            $this->config
        );
    }

    /**
     * Quick build for development with sensible defaults and full observability.
     */
    public function buildForDevelopment(): Cache
    {
        return $this
            ->withApcu(5)
            ->withFilesystem(null, 90)
            ->withFullObservability(true)
            ->withStrictKeyValidation(true)
            ->withStampedeProtection(true)
            ->build();
    }

    /**
     * Quick build for production with Redis + APCu and comprehensive monitoring.
     */
    public function buildForProduction(array $redisConfig = []): Cache
    {
        return $this
            ->withApcu(5)
            ->withRedis($redisConfig, 10)
            ->withFilesystem(null, 90)
            ->withFullObservability(true)
            ->withStrictKeyValidation(true)
            ->withStampedeProtection(true)
            ->withSlidingExpiration(true)
            ->build();
    }

    /**
     * Build for testing with array driver and full event tracking.
     */
    public function buildForTesting(): Cache
    {
        return $this
            ->addLayer('array', [], 5)
            ->withFullObservability(true)
            ->withStrictKeyValidation(true)
            ->withStampedeProtection(false) // Simplify for testing
            ->build();
    }

    /**
     * Build high-performance cache with minimal overhead.
     */
    public function buildHighPerformance(array $redisConfig = []): Cache
    {
        return $this
            ->withApcu(5)
            ->withRedis($redisConfig, 10)
            ->withMetrics(false) // Disable for maximum performance
            ->withEventLogging(false) // Disable for maximum performance
            ->withStrictKeyValidation(false) // Use basic validation for performance
            ->withStampedeProtection(true)
            ->build();
    }

    /**
     * Create a cache instance with custom event listener for specialized monitoring.
     */
    public function buildWithCustomEventListener(CacheEventListener $eventListener): Cache
    {
        return $this
            ->withEventListener($eventListener)
            ->withMetrics(true)
            ->build();
    }

    private function buildLayeredCache(): LayeredCache
    {
        $layeredCache = new LayeredCacheManager($this->logger);

        // Sort layers by priority
        usort($this->layers, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($this->layers as $layerConfig) {
            $driver = $this->driverManager->createDriver(
                $layerConfig['driver'],
                $layerConfig['config']
            );

            $pool = new CachePoolManager($driver);
            $layeredCache->addLayer($pool, $layerConfig['priority']);
        }

        return $layeredCache;
    }

    private function getDefaultConfig(): array
    {
        return [
            'default_ttl' => 3600,
            'sliding_expiration' => false,
            'stampede_protection' => true,
            'metrics_enabled' => true,
            'events_enabled' => true,
            'strict_validation' => true,
            'key_prefix' => '',
            'namespace_separator' => ':',
        ];
    }
}
