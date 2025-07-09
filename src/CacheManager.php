<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use Psr\Log\LoggerInterface;

/**
 * CacheManager with proper dependency injection following SOLID principles.
 *
 * This manager receives all dependencies injected and acts as a registry
 * for cache instances without creating its own dependencies.
 */
final class CacheManager
{
    private array $caches = [];
    private array $builders = [];

    public function __construct(
        private CacheConfig $config,
        private ConfigurationManager $configurator,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get cache instance by store name with lazy loading.
     */
    public function get(?string $name = null): Cache
    {
        $storeName = $name ?? $this->config->getDefaultStore();

        if (!isset($this->caches[$storeName])) {
            $this->caches[$storeName] = $this->buildCache($storeName);
        }

        return $this->caches[$storeName];
    }

    /**
     * Get cache instance with explicit store name.
     */
    public function getStore(string $storeName): Cache
    {
        return $this->get($storeName);
    }

    /**
     * Get default cache instance.
     */
    public function getDefault(): Cache
    {
        return $this->get();
    }

    /**
     * Create cache with injected builder.
     */
    public function createWithBuilder(string $name, CacheBuilder $builder): Cache
    {
        $cache = $builder->build();
        $this->caches[$name] = $cache;

        $this->logger->info('Custom cache instance created', [
            'store' => $name,
        ]);

        return $cache;
    }

    /**
     * Get a pre-configured builder for a store.
     */
    public function getBuilder(string $storeName): CacheBuilder
    {
        if (!isset($this->builders[$storeName])) {
            $this->builders[$storeName] = $this->createBuilder($storeName);
        }

        return clone $this->builders[$storeName];
    }

    /**
     * Create a new builder for a specific store.
     */
    public function createBuilder(string $storeName): CacheBuilder
    {
        if (!$this->has($storeName)) {
            throw ConfigurationException::missingDriver($storeName);
        }

        $builder = new CacheBuilder($this->config, $this->logger);
        $builder->configureFromStore($storeName);

        return $builder;
    }

    /**
     * Create cache instance directly without caching it.
     */
    public function createCache(string $storeName): Cache
    {
        $builder = $this->createBuilder($storeName);

        return $builder->build();
    }

    /**
     * Check if store is configured.
     */
    public function has(string $name): bool
    {
        return $this->config->has("stores.{$name}");
    }

    /**
     * Check if cache instance is loaded.
     */
    public function isLoaded(string $name): bool
    {
        return isset($this->caches[$name]);
    }

    /**
     * Get all configured store names.
     */
    public function getStoreNames(): array
    {
        return array_keys($this->config->getStores());
    }

    /**
     * Get all loaded cache instances.
     */
    public function getLoadedCaches(): array
    {
        return $this->caches;
    }

    /**
     * Forget cache instance (remove from registry).
     */
    public function forget(string $name): bool
    {
        if (isset($this->caches[$name])) {
            unset($this->caches[$name]);
            unset($this->builders[$name]);

            $this->logger->info('Cache instance forgotten', [
                'store' => $name,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Clear all loaded caches.
     */
    public function clearAll(): bool
    {
        $success = true;
        $results = [];

        foreach ($this->caches as $name => $cache) {
            try {
                $result = $cache->clear();
                $results[$name] = $result;
                if (!$result) {
                    $success = false;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to clear cache', [
                    'store' => $name,
                    'error' => $e->getMessage(),
                ]);
                $results[$name] = false;
                $success = false;
            }
        }

        $this->logger->info('Bulk cache clear completed', [
            'success' => $success,
            'results' => $results,
        ]);

        return $success;
    }

    /**
     * Warm up all loaded caches with data.
     */
    public function warmAll(array $data, $ttl = null): array
    {
        $results = [];

        foreach ($this->caches as $name => $cache) {
            try {
                $cache->warm($data, $ttl);
                $results[$name] = ['success' => true];
            } catch (\Exception $e) {
                $this->logger->error('Cache warm failed', [
                    'store' => $name,
                    'error' => $e->getMessage(),
                ]);
                $results[$name] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Get metrics from all loaded caches.
     */
    public function getAllMetrics(): array
    {
        $metrics = [];

        foreach ($this->caches as $name => $cache) {
            try {
                $metrics[$name] = $cache->getMetrics();
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get metrics', [
                    'store' => $name,
                    'error' => $e->getMessage(),
                ]);
                $metrics[$name] = ['error' => $e->getMessage()];
            }
        }

        return $metrics;
    }

    /**
     * Get consolidated statistics.
     */
    public function getStats(): array
    {
        return [
            'configured_stores' => count($this->getStoreNames()),
            'loaded_caches' => count($this->caches),
            'available_stores' => $this->getStoreNames(),
            'loaded_stores' => array_keys($this->caches),
            'default_store' => $this->config->getDefaultStore(),
            'configuration_valid' => empty($this->config->validate()),
        ];
    }

    /**
     * Get configuration instance.
     */
    public function getConfig(): CacheConfig
    {
        return $this->config;
    }

    /**
     * Get configurator instance.
     */
    public function getConfigurator(): ConfigurationManager
    {
        return $this->configurator;
    }

    /**
     * Get logger instance.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Validate all configurations.
     */
    public function validateConfiguration(): array
    {
        $errors = $this->config->validate();

        // Additional runtime validations
        foreach ($this->config->getStoreNames() as $storeName) {
            try {
                $this->validateStoreConfiguration($storeName);
            } catch (\Exception $e) {
                $errors[] = "Store '{$storeName}': " . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Reload configuration.
     */
    public function reloadConfiguration(): void
    {
        $this->logger->info('Reloading cache configuration');

        // Clear current instances and builders
        $this->caches = [];
        $this->builders = [];

        // Validate reloaded configuration
        $errors = $this->validateConfiguration();
        if (!empty($errors)) {
            $this->logger->warning('Configuration validation errors after reload', [
                'errors' => $errors,
            ]);
        }

        $this->logger->info('Cache configuration reloaded successfully');
    }

    /**
     * Build cache instance for a store using injected dependencies.
     */
    private function buildCache(string $storeName): Cache
    {
        try {
            if (!$this->has($storeName)) {
                throw ConfigurationException::missingDriver($storeName);
            }

            $builder = $this->createBuilder($storeName);
            $cache = $builder->build();

            $this->logger->info('Cache instance built', [
                'store' => $storeName,
                'driver' => $this->getStoreDriver($storeName),
            ]);

            return $cache;
        } catch (\Exception $e) {
            $this->logger->error('Failed to build cache', [
                'store' => $storeName,
                'error' => $e->getMessage(),
            ]);

            throw ConfigurationException::invalidConfiguration($storeName, "Failed to build cache: {$e->getMessage()}");
        }
    }

    /**
     * Get store driver type.
     */
    private function getStoreDriver(string $storeName): string
    {
        $store = $this->config->getStore($storeName);

        return $store['driver'] ?? 'unknown';
    }

    /**
     * Validate individual store configuration.
     */
    private function validateStoreConfiguration(string $storeName): void
    {
        $store = $this->config->getStore($storeName);
        $driver = $store['driver'] ?? null;

        if (!$driver) {
            throw ConfigurationException::invalidConfiguration($storeName, 'Missing driver configuration');
        }

        // Validate layered cache configuration
        if ('layered' === $driver) {
            $layers = $store['layers'] ?? [];
            if (empty($layers)) {
                throw ConfigurationException::invalidConfiguration($storeName, 'Layered cache must have at least one layer');
            }

            foreach ($layers as $index => $layer) {
                if (!isset($layer['driver'])) {
                    throw ConfigurationException::invalidConfiguration($storeName, "Layer {$index} missing driver configuration");
                }
            }
        }
    }
}
