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
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Config\StoreConfiguration;
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
    private readonly ConfigurationManager $configurator;
    private readonly string $namespace;
    private ?CacheConfiguration $cachedConfiguration = null;

    /**
     * @param ConfigurationManager $configurator The KaririCode configurator instance
     * @param string $namespace The configuration namespace (default: 'cache')
     */
    public function __construct(
        ConfigurationManager $configurator,
        string $namespace = 'cache'
    ) {
        $this->configurator = $configurator;
        $this->namespace = $namespace;
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
            $configArray = $this->extractConfigurationArray();
            $this->cachedConfiguration = new CacheConfiguration($configArray);
        }

        return $this->cachedConfiguration;
    }

    /**
     * Extract configuration array from configurator.
     */
    private function extractConfigurationArray(): array
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

    // Delegate all ConfigurationProvider methods to the underlying configuration

    public function getDefaultStore(): string
    {
        return $this->getConfiguration()->getDefaultStore();
    }

    public function getStores(): array
    {
        return $this->getConfiguration()->getStores();
    }

    public function getStore(string $name): StoreConfiguration
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
