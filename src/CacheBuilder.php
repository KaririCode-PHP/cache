<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Driver\Factory\DriverFactoryRegistry;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Modern cache builder with fluent API and intelligent defaults.
 */
final class CacheBuilder
{
    private array $layers = [];
    private ?Serializer $serializer = null;
    private ?StampedeProtectorContract $stampedeProtector = null;
    private ?MetricsCollectorContract $metricsCollector = null;
    private ?LoggerInterface $logger = null;
    private array $config = [];
    private DriverFactoryRegistry $factoryRegistry;

    public function __construct()
    {
        $this->factoryRegistry = new DriverFactoryRegistry();
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
     * Build the cache instance.
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
            $this->config
        );
    }

    /**
     * Quick build for development with sensible defaults.
     */
    public function buildForDevelopment(): Cache
    {
        return $this
            ->withApcu(5)
            ->withFilesystem(null, 90)
            ->withMetrics(true)
            ->withStampedeProtection(true)
            ->build();
    }

    /**
     * Quick build for production with Redis + APCu.
     */
    public function buildForProduction(array $redisConfig = []): Cache
    {
        return $this
            ->withApcu(5)
            ->withRedis($redisConfig, 10)
            ->withFilesystem(null, 90)
            ->withMetrics(true)
            ->withStampedeProtection(true)
            ->withSlidingExpiration(true)
            ->build();
    }

    private function buildLayeredCache(): LayeredCache
    {
        $layeredCache = new LayeredCacheManager($this->logger);

        // Sort layers by priority
        usort($this->layers, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($this->layers as $layerConfig) {
            $driver = $this->factoryRegistry->createDriver(
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
            'key_prefix' => '',
            'namespace_separator' => ':',
        ];
    }
}
