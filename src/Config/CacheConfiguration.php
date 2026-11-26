<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\HealthCheckConfig;
use KaririCode\Cache\Config\DTO\LoggingConfig;
use KaririCode\Cache\Config\DTO\MetricsConfig;
use KaririCode\Cache\Config\DTO\OptimizationConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * This approach ensures that default serializers are always available,
 * even when not explicitly configured, preventing validation errors.
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
    private readonly string $defaultSerializer;
    private readonly array $serializers;
    private readonly FeatureConfig $features;
    private readonly ValidationConfig $validation;
    private readonly MetricsConfig $metrics;
    private readonly LoggingConfig $logging;
    private readonly HealthCheckConfig $healthChecks;
    private readonly ExpirationProviderConfig $expirationProvider;
    private readonly OptimizationConfig $optimizations;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->validateConfiguration($config);

        // Build stores first
        $this->stores = $this->buildStores($config['stores'] ?? []);

        // Determine default store
        $this->defaultStore = $this->determineDefaultStore($config);

        // IMPORTANT FIX: Ensure serializers always include defaults
        $this->serializers = $this->buildSerializers($config['serializers'] ?? []);

        // Set default serializer with validation
        $this->defaultSerializer = $config['serializer'] ?? 'native';

        // Initialize DTOs
        $this->features = FeatureConfig::fromConfig($config['features'] ?? []);
        $this->validation = ValidationConfig::fromConfig($config['validation'] ?? []);
        $this->metrics = MetricsConfig::fromConfig($config['metrics'] ?? []);
        $this->logging = LoggingConfig::fromConfig($config['logging'] ?? []);
        $this->healthChecks = HealthCheckConfig::fromConfig($config['health_checks'] ?? []);
        $this->expirationProvider = ExpirationProviderConfig::fromConfig($config['expiration_provider'] ?? []);
        $this->optimizations = OptimizationConfig::fromConfig($config['optimizations'] ?? []);

        // Validate after construction
        $this->validateDefaultStore();
        $this->validateDefaultSerializer();
    }

    /**
     * Build serializers array, ensuring defaults are always included.
     *
     * @param array<string, class-string> $configuredSerializers
     *
     * @return array<string, class-string>
     */
    private function buildSerializers(array $configuredSerializers): array
    {
        $defaultSerializers = $this->getDefaultSerializers();

        // Merge configured serializers with defaults
        // Configured serializers take precedence over defaults
        return array_merge($defaultSerializers, $configuredSerializers);
    }

    /**
     * Determine the default store intelligently.
     *
     * @param array<string, mixed> $config
     */
    private function determineDefaultStore(array $config): string
    {
        // If explicitly configured, use it
        if (isset($config['default']) && is_string($config['default'])) {
            return $config['default'];
        }

        // If only one store exists, use it as default
        if (1 === count($this->stores)) {
            return array_key_first($this->stores);
        }

        // Prefer 'array' if it exists (safest default)
        if (isset($this->stores['array'])) {
            return 'array';
        }

        // Use the first available store
        return array_key_first($this->stores) ?? 'array';
    }

    /**
     * Get default serializers that should always be available.
     *
     * @return array<string, class-string>
     */
    private function getDefaultSerializers(): array
    {
        return [
            'native' => \KaririCode\Cache\Serializer\NativeSerializer::class,
            'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
            'igbinary' => \KaririCode\Cache\Serializer\IgbinarySerializer::class,
        ];
    }

    /**
     * Build stores from configuration.
     *
     * @param array<string, array<string, mixed>> $stores
     *
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
                $configurations[$name] = StoreConfig::fromConfig($config);
            } catch (\Exception $e) {
                throw ConfigurationException::invalidConfiguration("stores.{$name}", $e->getMessage());
            }
        }

        return $configurations;
    }

    /**
     * Validate the configuration array.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
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

    /**
     * Validate that the default store exists.
     *
     * @throws ConfigurationException
     */
    private function validateDefaultStore(): void
    {
        if (!$this->hasStore($this->defaultStore)) {
            throw ConfigurationException::missingStore($this->defaultStore);
        }
    }

    /**
     * Validate that the default serializer exists.
     *
     * @throws ConfigurationException
     */
    private function validateDefaultSerializer(): void
    {
        if (!isset($this->serializers[$this->defaultSerializer])) {
            throw ConfigurationException::invalidConfiguration('serializer', "Default serializer '{$this->defaultSerializer}' is not configured");
        }
    }

    // ConfigurationProvider interface implementation

    public function getDefaultStore(): string
    {
        return $this->defaultStore;
    }

    public function getStores(): array
    {
        return $this->stores;
    }

    public function getStore(string $name): StoreConfig
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
}
