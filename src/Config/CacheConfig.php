<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;

/**
 * Enhanced cache configuration manager integrated with KaririCode\Configurator.
 *
 * This class provides a seamless integration with the KaririCode\Configurator
 * component, allowing for flexible configuration management through multiple
 * file formats (PHP, JSON, YAML) while maintaining backward compatibility
 * and following SOLID principles.
 */
final class CacheConfig
{
    private ConfigurationManager $configurator;
    private string $namespace;
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
     * Get all cache stores configuration.
     */
    public function getStores(): array
    {
        return $this->get('stores', []);
    }

    /**
     * Get specific store configuration.
     */
    public function getStore(string $name): array
    {
        $stores = $this->getStores();

        if (!isset($stores[$name])) {
            throw ConfigurationException::missingDriver($name);
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
     * Get all store names.
     */
    public function getStoreNames(): array
    {
        return array_keys($this->getStores());
    }

    /**
     * Get layers configuration for layered cache.
     */
    public function getLayers(?string $storeName = null): array
    {
        $storeName = $storeName ?? $this->getDefaultStore();
        $store = $this->getStore($storeName);

        if ('layered' !== $store['driver']) {
            return [];
        }

        return $store['layers'] ?? [];
    }

    /**
     * Get serializer configuration.
     */
    public function getSerializer(): string
    {
        return $this->get('serializer', 'native');
    }

    /**
     * Check if feature is enabled.
     */
    public function isFeatureEnabled(string $feature): bool
    {
        return (bool) $this->get("features.{$feature}", false);
    }

    /**
     * Get all features configuration.
     */
    public function getFeatures(): array
    {
        return $this->get('features', []);
    }

    /**
     * Check if stampede protection is enabled.
     */
    public function isStampedeProtectionEnabled(): bool
    {
        return $this->isFeatureEnabled('stampede_protection');
    }

    /**
     * Check if metrics collection is enabled.
     */
    public function isMetricsEnabled(): bool
    {
        return $this->isFeatureEnabled('metrics_enabled');
    }

    /**
     * Check if sliding expiration is enabled.
     */
    public function isSlidingExpirationEnabled(): bool
    {
        return $this->isFeatureEnabled('sliding_expiration');
    }

    /**
     * Check if events are enabled.
     */
    public function isEventsEnabled(): bool
    {
        return $this->isFeatureEnabled('events_enabled');
    }

    /**
     * Check if PSR-14 events are enabled.
     */
    public function isPsr14EventsEnabled(): bool
    {
        return $this->isFeatureEnabled('psr14_events_enabled');
    }

    /**
     * Check if strict validation is enabled.
     */
    public function isStrictValidationEnabled(): bool
    {
        return $this->isFeatureEnabled('strict_validation');
    }

    /**
     * Get default TTL.
     */
    public function getDefaultTtl(): int
    {
        return (int) $this->get('defaults.ttl', 3600);
    }

    /**
     * Get default key prefix.
     */
    public function getDefaultKeyPrefix(): string
    {
        return (string) $this->get('defaults.key_prefix', '');
    }

    /**
     * Get namespace separator.
     */
    public function getNamespaceSeparator(): string
    {
        return (string) $this->get('defaults.namespace_separator', ':');
    }

    /**
     * Get security configuration.
     */
    public function getSecurity(): array
    {
        return $this->get('security', []);
    }

    /**
     * Get observability configuration.
     */
    public function getObservability(): array
    {
        return $this->get('observability', []);
    }

    /**
     * Get rate limit configuration.
     */
    public function getRateLimit(): int
    {
        return (int) $this->get('security.rate_limit', 1000);
    }

    /**
     * Get whitelisted IPs.
     */
    public function getWhitelistedIps(): array
    {
        return $this->get('security.whitelisted_ips', ['127.0.0.1', '::1']);
    }

    /**
     * Get slow operation threshold.
     */
    public function getSlowThreshold(): float
    {
        return (float) $this->get('observability.slow_threshold', 0.1);
    }

    /**
     * Get maximum events to store.
     */
    public function getMaxEvents(): int
    {
        return (int) $this->get('observability.max_events', 1000);
    }

    /**
     * Export all configuration as array.
     */
    public function toArray(): array
    {
        return $this->processedConfig;
    }

    /**
     * Validate configuration integrity.
     */
    public function validate(): array
    {
        $errors = [];

        // Validate default store exists
        $defaultStore = $this->getDefaultStore();
        $stores = $this->getStores();

        if (!isset($stores[$defaultStore])) {
            $errors[] = "Default store '{$defaultStore}' is not configured";
        }

        // Validate each store configuration
        foreach ($stores as $name => $config) {
            if (!isset($config['driver'])) {
                $errors[] = "Store '{$name}' missing driver configuration";
            }

            if ('layered' === $config['driver'] && empty($config['layers'])) {
                $errors[] = "Layered store '{$name}' has no layers configured";
            }
        }

        // Validate serializer
        $serializer = $this->getSerializer();
        $validSerializers = ['native', 'igbinary', 'json', 'compressed', 'compressed_igbinary'];

        if (!in_array($serializer, $validSerializers, true)) {
            $errors[] = "Invalid serializer '{$serializer}'. Valid options: " . implode(', ', $validSerializers);
        }

        // Validate TTL
        $ttl = $this->getDefaultTtl();
        if ($ttl < 0) {
            $errors[] = "Default TTL must be positive, got {$ttl}";
        }

        return $errors;
    }

    /**
     * Get configuration namespace.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get the underlying configurator instance.
     */
    public function getConfigurator(): ConfigurationManager
    {
        return $this->configurator;
    }

    /**
     * Create a new configuration instance with different namespace.
     */
    public function withNamespace(string $namespace): self
    {
        return new self($this->configurator, $namespace);
    }

    /**
     * Merge additional configuration.
     */
    public function merge(array $config): void
    {
        foreach ($config as $key => $value) {
            $namespacedKey = $this->getNamespacedKey($key);
            $this->configurator->set($namespacedKey, $value);
        }

        // Refresh processed configuration
        $this->processedConfig = $this->processConfiguration();
    }

    /**
     * Build namespaced key.
     */
    private function getNamespacedKey(string $key): string
    {
        if (empty($this->namespace)) {
            return $key;
        }

        return $this->namespace . '.' . $key;
    }

    /**
     * Process and normalize configuration.
     */
    private function processConfiguration(): array
    {
        $config = $this->configurator->has($this->namespace)
            ? $this->configurator->get($this->namespace)
            : [];

        // Ensure required keys exist with defaults
        $config = array_merge([
            'default' => 'array',
            'stores' => [],
            'serializer' => 'native',
            'features' => [],
            'defaults' => [],
            'security' => [],
            'observability' => [],
        ], $config);

        // Normalize features
        $config['features'] = array_merge([
            'stampede_protection' => true,
            'metrics_enabled' => true,
            'sliding_expiration' => false,
            'events_enabled' => true,
            'psr14_events_enabled' => false,
            'strict_validation' => true,
        ], $config['features']);

        // Normalize defaults
        $config['defaults'] = array_merge([
            'ttl' => 3600,
            'key_prefix' => '',
            'namespace_separator' => ':',
        ], $config['defaults']);

        // Normalize security
        $config['security'] = array_merge([
            'rate_limit' => 1000,
            'whitelisted_ips' => ['127.0.0.1', '::1'],
            'audit_salt' => 'cache_security_salt',
            'enable_geo_location' => false,
            'retention_days' => 30,
        ], $config['security']);

        // Normalize observability
        $config['observability'] = array_merge([
            'enable_performance_tracking' => true,
            'enable_security_monitoring' => false,
            'max_events' => 1000,
            'slow_threshold' => 0.1,
        ], $config['observability']);

        return $config;
    }
}
