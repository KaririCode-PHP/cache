<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\Adapter;

use KaririCode\Cache\Config\CacheConfiguration;
use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\HealthCheckConfig;
use KaririCode\Cache\Config\DTO\LoggingConfig;
use KaririCode\Cache\Config\DTO\MetricsConfig;
use KaririCode\Cache\Config\DTO\OptimizationConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use KaririCode\Contract\ImmutableValue;

/**
 * Adapter for KaririCode\Configurator integration.
 *
 * This adapter implements the Adapter Pattern to seamlessly integrate
 * with the KaririCode Configuration component while maintaining
 * the same interface as our configuration provider.
 *
 * Design Patterns:
 * - Adapter Pattern: Adapts ConfigurationManager to ConfigurationProvider
 * - Lazy Loading: Configuration is processed on-demand
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 */
final class ConfiguratorAdapter implements ConfigurationProvider, ImmutableValue
{
    private ?CacheConfiguration $cachedConfiguration = null;

    /**
     * @param ConfigurationManager $configurator The KaririCode configurator instance
     * @param string $namespace The configuration namespace (default: 'cache')
     */
    public function __construct(
        private readonly ConfigurationManager $configurator,
        private readonly string $namespace = 'cache'
    ) {
    }

    /**
     * Factory method for creating from configurator.
     */
    public static function fromConfigurator(
        ConfigurationManager $configurator,
        string $namespace = 'cache'
    ): self {
        return new self($configurator, $namespace);
    }

    /**
     * Get the underlying configuration lazily.
     */
    private function getConfiguration(): CacheConfiguration
    {
        if (null === $this->cachedConfiguration) {
            $configArray = $this->extractConfigurationData();
            $this->cachedConfiguration = new CacheConfiguration($configArray);
        }

        return $this->cachedConfiguration;
    }

    /**
     * Extract configuration array from configurator.
     */
    private function extractConfigurationData(): array
    {
        $config = $this->configurator->has($this->namespace)
            ? $this->configurator->get($this->namespace)
            : [];

        $config = is_array($config) ? $config : [];

        // Get default serializers
        $defaultSerializers = $this->getDefaultSerializers();

        // Merge configured serializers with defaults
        $configuredSerializers = $config['serializers'] ?? [];
        $mergedSerializers = array_merge($defaultSerializers, $configuredSerializers);

        // Apply defaults with merged serializers
        $defaults = [
            'default' => 'array',
            'stores' => [],
            'serializer' => 'native',
            'serializers' => $mergedSerializers, // Use merged serializers
            'features' => [],
            'validation' => [],
            'metrics' => [],
            'logging' => [],
            'health_checks' => [],
            'expiration_provider' => [],
            'optimizations' => [],
        ];

        // Merge configuration with defaults
        $mergedConfig = array_merge($defaults, $config);

        // Ensure serializers always include defaults
        if (isset($config['serializers'])) {
            $mergedConfig['serializers'] = array_merge($defaultSerializers, $config['serializers']);
        }

        return $mergedConfig;
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

    // Delegate all ConfigurationProvider methods to the underlying configuration

    public function getDefaultStore(): string
    {
        return $this->getConfiguration()->getDefaultStore();
    }

    public function getStores(): array
    {
        return $this->getConfiguration()->getStores();
    }

    public function getStore(string $name): StoreConfig
    {
        return $this->getConfiguration()->getStore($name);
    }

    public function hasStore(string $name): bool
    {
        return $this->getConfiguration()->hasStore($name);
    }

    public function getDefaultSerializer(): string
    {
        return $this->getConfiguration()->getDefaultSerializer();
    }

    public function getSerializers(): array
    {
        return $this->getConfiguration()->getSerializers();
    }

    public function getFeatures(): FeatureConfig
    {
        return $this->getConfiguration()->getFeatures();
    }

    public function getValidation(): ValidationConfig
    {
        return $this->getConfiguration()->getValidation();
    }

    public function getMetrics(): MetricsConfig
    {
        return $this->getConfiguration()->getMetrics();
    }

    public function getLogging(): LoggingConfig
    {
        return $this->getConfiguration()->getLogging();
    }

    public function getHealthChecks(): HealthCheckConfig
    {
        return $this->getConfiguration()->getHealthChecks();
    }

    public function getExpirationProvider(): ExpirationProviderConfig
    {
        return $this->getConfiguration()->getExpirationProvider();
    }

    public function getOptimizations(): OptimizationConfig
    {
        return $this->getConfiguration()->getOptimizations();
    }
}
