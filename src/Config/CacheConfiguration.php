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
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * Immutable cache configuration implementation.
 *
 * This class serves as the primary configuration container,
 * implementing the Value Object pattern for immutability.
 *
 * Design Patterns:
 * - Value Object: Immutable configuration representation
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 */
final class CacheConfiguration implements ConfigurationProvider, ImmutableValue
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
        $this->features = FeatureConfig::fromConfig($config['features'] ?? []);
        $this->validation = ValidationConfig::fromConfig($config['validation'] ?? []);
        $this->metrics = MetricsConfig::fromConfig($config['metrics'] ?? []);
        $this->logging = LoggingConfig::fromConfig($config['logging'] ?? []);
        $this->healthChecks = HealthCheckConfig::fromConfig($config['health_checks'] ?? []);
        $this->expirationProvider = ExpirationProviderConfig::fromConfig($config['expiration_provider'] ?? []);
        $this->optimizations = OptimizationConfig::fromConfig($config['optimizations'] ?? []);

        $this->validateDefaultStore();
        $this->validateDefaultSerializer();
    }

    // region ConfigurationProvider Interface Implementation
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

    public function getStore(string $name): StoreConfiguration
    {
        if (!isset($this->stores[$name])) {
            throw ConfigurationException::missingStore($name);
        }

        return $this->stores[$name];
    }

    public function hasStore(string $name): bool
    {
        return isset($this->stores[$name]);
    }

    public function getDefaultSerializer(): string
    {
        return $this->defaultSerializer;
    }

    public function getSerializers(): array
    {
        return $this->serializers;
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
    // endregion

    // region Private Helper Methods
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
    // endregion
}
