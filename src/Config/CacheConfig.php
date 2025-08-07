<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\HealthCheckConfig;
use KaririCode\Cache\Config\DTO\LoggingConfig;
use KaririCode\Cache\Config\DTO\MetricsConfig;
use KaririCode\Cache\Config\DTO\OptimizationConfig;
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use KaririCode\Contract\ImmutableValue;

/**
 * Enhanced cache configuration manager integrated with KaririCode\Configurator.
 *
 * This class provides a seamless integration with the KaririCode\Configurator
 * component, allowing for flexible configuration management through multiple
 * file formats (PHP, JSON, YAML) while maintaining backward compatibility
 * and following SOLID principles.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class CacheConfig implements ImmutableValue
{
    private ConfigurationManager $configurator;
    private string $namespace;

    /** @var array<string, mixed> */
    private array $processedConfig;

    /**
     * Initialize with KaririCode\Configurator instance.
     *
     * @param ConfigurationManager $configurator The configurator instance
     * @param string $namespace The configuration namespace (default: 'cache')
     */
    public function __construct(
        ConfigurationManager $configurator,
        string $namespace = 'cache'
    ) {
        $this->configurator = $configurator;
        $this->namespace = $namespace;
        $this->processedConfig = $this->processConfiguration();
    }

    /**
     * Create configuration from files with environment support.
     */
    public static function fromConfigurator(
        ConfigurationManager $configurator,
        string $namespace = 'cache'
    ): self {
        return new self($configurator, $namespace);
    }

    /**
     * Get configuration value with namespace support.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $namespacedKey = $this->getNamespacedKey($key);

        return $this->configurator->get($namespacedKey, $default);
    }

    /**
     * Check if configuration key exists.
     */
    public function has(string $key): bool
    {
        $namespacedKey = $this->getNamespacedKey($key);

        return $this->configurator->has($namespacedKey);
    }

    /**
     * Check if store exists.
     */
    public function hasStore(string $name): bool
    {
        $stores = $this->getStores();

        return isset($stores[$name]);
    }

    /**
     * Get all cache stores configuration.
     *
     * @return array<string, StoreConfiguration> associative array of store names and their configurations
     */
    public function getStores(): array
    {
        $storesData = $this->get('stores', []);
        /** @phpstan-var array<string, array<string, mixed>> $storesData */
        $stores = [];

        foreach ($storesData as $name => $config) {
            if (is_array($config)) {
                $stores[$name] = new StoreConfiguration($name, $config);
            }
        }

        return $stores;
    }

    /**
     * Get specific store configuration.
     */
    public function getStore(string $name): StoreConfiguration
    {
        $stores = $this->getStores();
        if (!isset($stores[$name])) {
            throw ConfigurationException::missingStore($name);
        }

        return $stores[$name];
    }

    /**
     * Get default store name.
     */
    public function getDefaultStore(): string
    {
        return $this->get('default', 'array');
    }

    /**
     * Get serializers configuration.
     */
    public function getSerializers(): array
    {
        return $this->get('serializers', []);
    }

    /**
     * Get default serializer name.
     */
    public function getDefaultSerializer(): string
    {
        return $this->get('serializer', 'native');
    }

    /**
     * Get feature configuration.
     */
    public function getFeatures(): FeatureConfig
    {
        $features = $this->get('features', []);

        return new FeatureConfig($features);
    }

    /**
     * Get validation configuration.
     */
    public function getValidation(): ValidationConfig
    {
        $validation = $this->get('validation', []);

        return new ValidationConfig($validation);
    }

    /**
     * Get metrics configuration.
     */
    public function getMetrics(): MetricsConfig
    {
        $metrics = $this->get('metrics', []);

        return new MetricsConfig($metrics);
    }

    /**
     * Get logging configuration.
     */
    public function getLogging(): LoggingConfig
    {
        $logging = $this->get('logging', []);

        return new LoggingConfig($logging);
    }

    /**
     * Get health check configuration.
     */
    public function getHealthChecks(): HealthCheckConfig
    {
        $healthChecks = $this->get('health_checks', []);

        return new HealthCheckConfig($healthChecks);
    }

    /**
     * Get expiration provider configuration.
     */
    public function getExpirationProvider(): ExpirationProviderConfig
    {
        $expirationProvider = $this->get('expiration_provider', []);

        return new ExpirationProviderConfig($expirationProvider);
    }

    /**
     * Get optimizations configuration.
     */
    public function getOptimizations(): OptimizationConfig
    {
        $optimizations = $this->get('optimizations', []);

        return new OptimizationConfig($optimizations);
    }

    /**
     * Convert to CacheConfiguration for compatibility.
     */
    public function toCacheConfiguration(): CacheConfiguration
    {
        return new CacheConfiguration([
            'default' => $this->getDefaultStore(),
            'stores' => $this->processedConfig['stores'] ?? [],
            'serializers' => $this->getSerializers(),
            'serializer' => $this->getDefaultSerializer(),
            'features' => $this->get('features', []),
            'validation' => $this->get('validation', []),
            'metrics' => $this->get('metrics', []),
            'logging' => $this->get('logging', []),
            'health_checks' => $this->get('health_checks', []),
            'expiration_provider' => $this->get('expiration_provider', []),
            'optimizations' => $this->get('optimizations', []),
        ]);
    }

    private function getNamespacedKey(string $key): string
    {
        return $this->namespace . '.' . $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function processConfiguration(): array
    {
        $config = $this->configurator->has($this->namespace)
            ? $this->configurator->get($this->namespace)
            : [];

        $config = is_array($config) ? $config : [];

        // Apply defaults
        $defaults = [
            'default' => 'array',
            'stores' => [],
            'serializer' => 'native',
            'serializers' => [],
            'features' => [],
            'validation' => [],
            'metrics' => [],
            'logging' => [],
            'health_checks' => [],
            'expiration_provider' => [],
            'optimizations' => [],
        ];

        return array_merge($defaults, $config);
    }
}
