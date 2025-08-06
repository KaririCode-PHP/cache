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
use KaririCode\Contract\ImmutableValue;

/**
 * Cache Configuration Manager.
 *
 * This class manages all cache configuration, validating and providing
 * access to store configurations, serializers, and other settings.
 * Implements the Value Object pattern as an immutable configuration.
 *
 * Design Patterns:
 * - Value Object: Immutable configuration representation
 * - Builder Pattern: Can be built from arrays
 * - Facade Pattern: Simplifies access to complex configuration
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class CacheConfiguration implements ImmutableValue
{
    private readonly string $defaultStore;
    private readonly array $stores;
    private readonly array $serializers;
    private readonly string $defaultSerializer;
    private readonly FeatureConfig $features;
    private readonly ValidationConfig $validation;
    private readonly MetricsConfig $metrics;
    private readonly LoggingConfig $logging;
    private readonly HealthCheckConfig $healthChecks;
    private readonly ExpirationProviderConfig $expirationProvider;
    private readonly OptimizationConfig $optimizations;

    /**
     * @param array<string, mixed> $config Raw configuration array
     *
     * @throws ConfigurationException If configuration is invalid
     */
    public function __construct(array $config)
    {
        $this->validateConfiguration($config);

        $this->defaultStore = $config['default'] ?? 'array';
        $this->stores = $this->buildStores($config['stores'] ?? []);
        $this->serializers = $config['serializers'] ?? $this->getDefaultSerializers();
        $this->defaultSerializer = $config['serializer'] ?? 'native';
        $this->features = new FeatureConfig($config['features'] ?? []);
        $this->validation = new ValidationConfig($config['validation'] ?? []);
        $this->metrics = new MetricsConfig($config['metrics'] ?? []);
        $this->logging = new LoggingConfig($config['logging'] ?? []);
        $this->healthChecks = new HealthCheckConfig($config['health_checks'] ?? []);
        $this->expirationProvider = new ExpirationProviderConfig($config['expiration_provider'] ?? []);
        $this->optimizations = new OptimizationConfig($config['optimizations'] ?? []);

        $this->validateDefaultStore();
        $this->validateDefaultSerializer();
    }

    public function getDefaultStore(): string
    {
        return $this->defaultStore;
    }

    /**
     * @return array<string, StoreConfiguration>
     */
    public function getStores(): array
    {
        return $this->stores;
    }

    public function getStore(string $name): ?StoreConfiguration
    {
        return $this->stores[$name] ?? null;
    }

    public function hasStore(string $name): bool
    {
        return isset($this->stores[$name]);
    }

    public function getSerializers(): array
    {
        return $this->serializers;
    }

    public function getDefaultSerializer(): string
    {
        return $this->defaultSerializer;
    }

    public function getFeatures(): FeatureConfig
    {
        return $this->features;
    }

    public function getValidation(): ValidationConfig
    {
        return $this->validation;
    }

    public function getMetrics(): MetricsConfig
    {
        return $this->metrics;
    }

    public function getLogging(): LoggingConfig
    {
        return $this->logging;
    }

    public function getHealthChecks(): HealthCheckConfig
    {
        return $this->healthChecks;
    }

    public function getExpirationProvider(): ExpirationProviderConfig
    {
        return $this->expirationProvider;
    }

    public function getOptimizations(): OptimizationConfig
    {
        return $this->optimizations;
    }

    /**
     * @return array<string, StoreConfiguration>
     */
    private function buildStores(array $stores): array
    {
        $configurations = [];

        foreach ($stores as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                throw ConfigurationException::invalidConfiguration("stores.{$name}", 'Store configuration must be an array');
            }

            try {
                $configurations[$name] = new StoreConfiguration($name, $config);
            } catch (\Exception $e) {
                throw ConfigurationException::invalidConfiguration("stores.{$name}", $e->getMessage());
            }
        }

        return $configurations;
    }

    private function validateConfiguration(array $config): void
    {
        if (isset($config['default']) && !is_string($config['default'])) {
            throw ConfigurationException::invalidConfiguration('default', 'Default store must be a string');
        }

        if (isset($config['stores']) && !is_array($config['stores'])) {
            throw ConfigurationException::invalidConfiguration('stores', 'Stores must be an array');
        }

        if (empty($config['stores'])) {
            throw ConfigurationException::invalidConfiguration('stores', 'At least one cache store must be configured');
        }
    }

    private function validateDefaultStore(): void
    {
        if (!$this->hasStore($this->defaultStore)) {
            throw ConfigurationException::missingStore($this->defaultStore);
        }
    }

    private function validateDefaultSerializer(): void
    {
        if (!isset($this->serializers[$this->defaultSerializer])) {
            throw ConfigurationException::invalidConfiguration('serializer', "Default serializer '{$this->defaultSerializer}' is not configured");
        }
    }

    private function getDefaultSerializers(): array
    {
        return [
            'native' => \KaririCode\Cache\Serializer\NativeSerializer::class,
            'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
            'igbinary' => \KaririCode\Cache\Serializer\IgbinarySerializer::class,
        ];
    }
}
