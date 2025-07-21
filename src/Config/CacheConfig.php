<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Config\DTO\StoreConfig;
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
     * Get all cache stores configuration.
     *
     * @return array<string, StoreConfig> associative array of store names and their configurations
     */
    public function getStores(): array
    {
        $storesData = $this->get('stores', []);
        /** @phpstan-var array<string, array<string, mixed>> $storesData */
        $stores = [];
        foreach ($storesData as $name => $config) {
            if (is_array($config)) {
                $stores[$name] = new StoreConfig($config);
            }
        }

        return $stores;
    }

    /**
     * Get specific store configuration.
     *
     * @param string $name the store name
     *
     * @return StoreConfig the store configuration
     */
    public function getStore(string $name): StoreConfig
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
        $defaultStore = $this->get('default', 'array');

        /** @phpstan-var string $defaultStore */
        return $defaultStore;
    }

    /**
     * Get all store names.
     *
     * @return list<string> list of store names
     */
    public function getStoreNames(): array
    {
        return array_keys($this->getStores());
    }

    /**
     * Get layers configuration for layered cache.
     * If no store name is provided, returns layers for the default store.
     *
     * @param string|null $storeName the store name (default: null for default store)
     *
     * @return list<array{driver: string, config: array<string, mixed>, priority: int}>
     */
    public function getLayers(?string $storeName = null): array
    {
        $storeName = $storeName ?? $this->getDefaultStore();
        $store = $this->getStore($storeName);

        if ('layered' !== $store->driver) {
            return [];
        }

        return $store->layers;
    }

    /**
     * Get serializer configuration.
     */
    public function getSerializer(): string
    {
        $serializer = $this->get('serializer', 'native');

        /** @phpstan-var string $serializer */
        return $serializer;
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
     *
     * @return array<string, mixed> associative array of feature names and their configurations
     */
    public function getFeatures(): array
    {
        $features = $this->get('features', []);

        /** @phpstan-var array<string, mixed> $features */
        return $features;
    }

    public function isStampedeProtectionEnabled(): bool
    {
        return $this->isFeatureEnabled('stampede_protection');
    }

    public function isMetricsEnabled(): bool
    {
        return $this->isFeatureEnabled('metrics_enabled');
    }

    public function isSlidingExpirationEnabled(): bool
    {
        return $this->isFeatureEnabled('sliding_expiration');
    }

    public function isEventsEnabled(): bool
    {
        return $this->isFeatureEnabled('events_enabled');
    }

    public function isPsr14EventsEnabled(): bool
    {
        return $this->isFeatureEnabled('psr14_events_enabled');
    }

    public function isStrictValidationEnabled(): bool
    {
        return $this->isFeatureEnabled('strict_validation');
    }

    /**
     * Get default TTL.
     */
    public function getDefaultTtl(): int
    {
        $ttl = $this->get('defaults.ttl', 3600);

        /** @phpstan-var int|string $ttl */
        return (int) $ttl;
    }

    /**
     * Get default key prefix.
     */
    public function getDefaultKeyPrefix(): string
    {
        $prefix = $this->get('defaults.key_prefix', '');

        /** @phpstan-var string $prefix */
        return (string) $prefix;
    }

    /**
     * Get namespace separator.
     */
    public function getNamespaceSeparator(): string
    {
        $separator = $this->get('defaults.namespace_separator', ':');

        /** @phpstan-var string $separator */
        return (string) $separator;
    }

    /**
     * Get security configuration.
     *
     * @return array<string, mixed> associative array of security settings
     */
    public function getSecurity(): array
    {
        $security = $this->get('security', []);

        /** @phpstan-var array<string, mixed> $security */
        return $security;
    }

    /**
     * Get observability configuration.
     *
     * @return array<string, mixed> associative array of observability settings
     */
    public function getObservability(): array
    {
        $observability = $this->get('observability', []);

        /** @phpstan-var array<string, mixed> $observability */
        return $observability;
    }

    /**
     * Get rate limit configuration.
     */
    public function getRateLimit(): int
    {
        $rateLimit = $this->get('security.rate_limit', 1000);

        /** @phpstan-var int|string $rateLimit */
        return (int) $rateLimit;
    }

    /**
     * Get whitelisted IPs.
     *
     * @return list<string> list of whitelisted IP addresses
     */
    public function getWhitelistedIps(): array
    {
        $ips = $this->get('security.whitelisted_ips', ['127.0.0.1', '::1']);

        /** @phpstan-var list<string> $ips */
        return $ips;
    }

    /**
     * Get slow operation threshold.
     */
    public function getSlowThreshold(): float
    {
        $threshold = $this->get('observability.slow_threshold', 0.1);

        /** @phpstan-var float|int|string $threshold */
        return (float) $threshold;
    }

    /**
     * Get maximum events to store.
     */
    public function getMaxEvents(): int
    {
        $maxEvents = $this->get('observability.max_events', 1000);

        /** @phpstan-var int|string $maxEvents */
        return (int) $maxEvents;
    }

    /**
     * Export all configuration as array.
     *
     * @return array<string, mixed> associative array of all configuration values
     */
    public function toArray(): array
    {
        return $this->processedConfig;
    }

    /**
     * Validate configuration integrity.
     *
     * @return list<string> list of validation error messages
     */
    public function validate(): array
    {
        $errors = [];
        $defaultStore = $this->getDefaultStore();
        $stores = $this->getStores();

        if (!isset($stores[$defaultStore])) {
            $errors[] = "Default store '{$defaultStore}' is not configured";
        }

        foreach ($stores as $name => $config) {
            if ('layered' === $config->driver && empty($config->layers)) {
                $errors[] = "Layered store '{$name}' has no layers configured";
            }
        }

        $serializer = $this->getSerializer();
        $validSerializers = ['native', 'igbinary', 'json', 'compressed', 'compressed_igbinary'];
        if (!in_array($serializer, $validSerializers, true)) {
            $errors[] = "Invalid serializer '{$serializer}'. Valid options: " . implode(', ', $validSerializers);
        }

        $ttl = $this->getDefaultTtl();
        if ($ttl < 0) {
            $errors[] = "Default TTL must be positive, got {$ttl}";
        }

        return $errors;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getConfigurator(): ConfigurationManager
    {
        return $this->configurator;
    }

    public function withNamespace(string $namespace): self
    {
        return new self($this->configurator, $namespace);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function merge(array $config): void
    {
        foreach ($config as $key => $value) {
            $namespacedKey = $this->getNamespacedKey($key);
            $this->configurator->set($namespacedKey, $value);
        }
        $this->processedConfig = $this->processConfiguration();
    }

    private function getNamespacedKey(string $key): string
    {
        if (empty($this->namespace)) {
            return $key;
        }

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

        $defaults = [
            'default' => 'array', 'stores' => [], 'serializer' => 'native',
            'features' => [], 'defaults' => [], 'security' => [], 'observability' => [],
        ];
        $config = array_merge($defaults, $config);

        $features = is_array($config['features']) ? $config['features'] : [];
        $config['features'] = array_merge([
            'stampede_protection' => true, 'metrics_enabled' => true, 'sliding_expiration' => false,
            'events_enabled' => true, 'psr14_events_enabled' => false, 'strict_validation' => true,
        ], $features);

        $defaultsConfig = is_array($config['defaults']) ? $config['defaults'] : [];
        $config['defaults'] = array_merge([
            'ttl' => 3600, 'key_prefix' => '', 'namespace_separator' => ':',
        ], $defaultsConfig);

        $securityConfig = is_array($config['security']) ? $config['security'] : [];
        $config['security'] = array_merge([
            'rate_limit' => 1000, 'whitelisted_ips' => ['127.0.0.1', '::1'],
            'audit_salt' => 'cache_security_salt', 'enable_geo_location' => false, 'retention_days' => 30,
        ], $securityConfig);

        $observabilityConfig = is_array($config['observability']) ? $config['observability'] : [];
        $config['observability'] = array_merge([
            'enable_performance_tracking' => true, 'enable_security_monitoring' => false,
            'max_events' => 1000, 'slow_threshold' => 0.1,
        ], $observabilityConfig);

        return $config;
    }
}
