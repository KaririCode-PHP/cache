<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cache manager with instance registry and lazy loading.
 */
final class CacheManager
{
    private static ?self $instance = null;
    private array $caches = [];
    private CacheConfig $config;
    private LoggerInterface $logger;

    private function __construct(CacheConfig $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Initialize the cache manager with configuration.
     */
    public static function initialize(CacheConfig $config, ?LoggerInterface $logger = null): self
    {
        self::$instance = new self($config, $logger);

        return self::$instance;
    }

    /**
     * Get the singleton instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            throw new \RuntimeException('CacheManager not initialized. Call initialize() first.');
        }

        return self::$instance;
    }

    /**
     * Get a cache instance by name.
     */
    public function get(string $name = 'default'): Cache
    {
        if (!isset($this->caches[$name])) {
            $this->caches[$name] = $this->createCache($name);
        }

        return $this->caches[$name];
    }

    /**
     * Create a named cache instance.
     */
    public function create(string $name, CacheConfig $config): Cache
    {
        $builder = CacheBuilder::create();

        // Configure layers
        foreach ($config->getLayers() as $layerConfig) {
            $builder->addLayer(
                $layerConfig['driver'],
                $layerConfig['config'] ?? [],
                $layerConfig['priority'] ?? 100
            );
        }

        // Configure serializer
        $serializerType = $config->getSerializer();
        if ('native' !== $serializerType) {
            $serializer = $this->createSerializer($serializerType);
            $builder->withSerializer($serializer);
        }

        // Configure features
        $builder
            ->withStampedeProtection($config->isStampedeProtectionEnabled())
            ->withMetrics($config->isMetricsEnabled())
            ->withSlidingExpiration($config->isSlidingExpirationEnabled())
            ->withDefaultTtl($config->getDefaultTtl())
            ->withLogger($this->logger);

        $cache = $builder->build();
        $this->caches[$name] = $cache;

        return $cache;
    }

    /**
     * Get all registered cache instances.
     */
    public function all(): array
    {
        return $this->caches;
    }

    /**
     * Clear all caches.
     */
    public function clearAll(): bool
    {
        $success = true;

        foreach ($this->caches as $cache) {
            if (!$cache->clear()) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get metrics from all caches.
     */
    public function getAllMetrics(): array
    {
        $metrics = [];

        foreach ($this->caches as $name => $cache) {
            $metrics[$name] = $cache->getMetrics();
        }

        return $metrics;
    }

    private function createCache(string $name): Cache
    {
        if ('default' === $name) {
            return $this->create($name, $this->config);
        }

        throw ConfigurationException::missingDriver($name);
    }

    private function createSerializer(string $type): Contract\Serializer
    {
        return match ($type) {
            'igbinary' => new Serializer\IgbinarySerializer(),
            'json' => new Serializer\JsonSerializer(),
            'compressed' => new Serializer\CompressingSerializer(
                new Serializer\NativeSerializer()
            ),
            'compressed_igbinary' => new Serializer\CompressingSerializer(
                new Serializer\IgbinarySerializer()
            ),
            default => new Serializer\NativeSerializer(),
        };
    }
}
