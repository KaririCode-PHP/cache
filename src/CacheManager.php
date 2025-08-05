<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use Psr\Log\LoggerInterface;

/**
 * Manages cache instances, acting as a factory and registry.
 *
 * This class provides a central point of access for creating and retrieving
 * named cache stores, ensuring that instances are lazy-loaded and reused.
 */
final class CacheManager
{
    /** @var array<string, Cache> */
    private array $caches = [];

    /** @var array<string, CacheBuilder> */
    private array $builders = [];

    public function __construct(
        private readonly CacheConfig $config,
        private readonly ConfigurationManager $configurator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get a cache instance by name.
     *
     * If no name is provided, the default cache store will be returned.
     *
     * @param string|null $name the name of the cache store
     *
     * @throws ConfigurationException if the store configuration is missing or invalid
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
     * Get a cache instance by name (alias for get()).
     *
     * @param string $storeName the name of the cache store
     */
    public function getStore(string $storeName): Cache
    {
        return $this->get($storeName);
    }

    /**
     * Get the default cache instance.
     */
    public function getDefault(): Cache
    {
        return $this->get();
    }

    /**
     * Create and register a custom cache instance using a pre-configured builder.
     *
     * @param string $name the name to register the cache instance under
     * @param CacheBuilder $builder the configured CacheBuilder instance
     *
     * @return Cache the newly created cache instance
     */
    public function createWithBuilder(string $name, CacheBuilder $builder): Cache
    {
        $cache = $builder->build();
        $this->caches[$name] = $cache;

        $this->logger->info('Custom cache instance created', ['store' => $name]);

        return $cache;
    }

    /**
     * Get a pre-configured CacheBuilder instance for a specific store.
     *
     * @param string $storeName the name of the store
     *
     * @return CacheBuilder a new, cloned builder instance
     */
    public function getBuilder(string $storeName): CacheBuilder
    {
        if (!isset($this->builders[$storeName])) {
            $this->builders[$storeName] = $this->createBuilder($storeName);
        }

        return clone $this->builders[$storeName];
    }

    /**
     * Create a new CacheBuilder instance for a specific store.
     *
     * @param string $storeName the name of the store
     *
     * @throws ConfigurationException if the store is not configured
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
     * Create a new Cache instance for a specific store.
     *
     * @param string $storeName the name of the store
     */
    public function createCache(string $storeName): Cache
    {
        $builder = $this->createBuilder($storeName);

        return $builder->build();
    }

    /**
     * Check if a cache store is configured.
     *
     * @param string $name the name of the store
     */
    public function has(string $name): bool
    {
        return $this->config->has("stores.{$name}");
    }

    /**
     * Check if a cache instance has been loaded (instantiated).
     *
     * @param string $name the name of the store
     */
    public function isLoaded(string $name): bool
    {
        return isset($this->caches[$name]);
    }

    /**
     * Get the names of all configured cache stores.
     *
     * @return list<string> a list of store names
     */
    public function getStoreNames(): iterable
    {
        return $this->config->getStoreNames();
    }

    /**
     * Get all currently loaded cache instances.
     *
     * @return array<string, Cache>
     */
    public function getLoadedCaches(): iterable
    {
        return $this->caches;
    }

    /**
     * Remove a cache instance from the manager.
     *
     * @param string $name the name of the store to forget
     *
     * @return bool true if the instance was found and removed, false otherwise
     */
    public function forget(string $name): bool
    {
        if (isset($this->caches[$name])) {
            unset($this->caches[$name], $this->builders[$name]);
            $this->logger->info('Cache instance forgotten', ['store' => $name]);

            return true;
        }

        return false;
    }

    /**
     * Clear all loaded cache instances.
     *
     * @return bool true if all clear operations were successful, false otherwise
     */
    public function clearAll(): bool
    {
        $success = true;
        foreach ($this->caches as $name => $cache) {
            try {
                if (!$cache->clear()) {
                    $success = false;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to clear cache', ['store' => $name, 'error' => $e->getMessage()]);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Pre-populates all loaded caches with the given data.
     *
     * @param array<string, mixed> $data
     */
    public function warmAll(iterable $data): iterable
    {
        $results = [];
        foreach ($this->caches as $name => $cache) {
            try {
                $cache->warm($data);
                $results[$name] = ['success' => true];
            } catch (\Exception $e) {
                $this->logger->error('Cache warm failed', ['store' => $name, 'error' => $e->getMessage()]);
                $results[$name] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Get performance metrics from all loaded cache instances.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllMetrics(): iterable
    {
        $metrics = [];
        foreach ($this->caches as $name => $cache) {
            try {
                $metrics[$name] = $cache->getMetrics()->toArray();
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get metrics', ['store' => $name, 'error' => $e->getMessage()]);
                $metrics[$name] = ['error' => $e->getMessage()];
            }
        }

        return $metrics;
    }

    /**
     * Get statistics about the cache manager itself.
     *
     * @return array<string, mixed>
     */
    public function getStats(): iterable
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

    public function getConfig(): CacheConfig
    {
        return $this->config;
    }

    public function getConfigurator(): ConfigurationManager
    {
        return $this->configurator;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Validates the entire cache configuration.
     *
     * @return list<string> a list of validation error messages
     */
    public function validateConfiguration(): iterable
    {
        $errors = $this->config->validate();
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
     * Reloads the cache configuration and purges all existing instances.
     */
    public function reloadConfiguration(): void
    {
        $this->logger->info('Reloading cache configuration');
        $this->caches = [];
        $this->builders = [];

        $errors = $this->validateConfiguration();
        if (!empty($errors)) {
            $this->logger->warning('Configuration validation errors after reload', ['errors' => $errors]);
        }

        $this->logger->info('Cache configuration reloaded successfully');
    }

    private function buildCache(string $storeName): Cache
    {
        try {
            $builder = $this->createBuilder($storeName);
            $cache = $builder->build();
            $this->logger->info('Cache instance built', [
                'store' => $storeName,
                'driver' => $this->getStoreDriver($storeName),
            ]);

            return $cache;
        } catch (\Exception $e) {
            $this->logger->error('Failed to build cache', ['store' => $storeName, 'error' => $e->getMessage()]);
            throw ConfigurationException::invalidConfiguration($storeName, "Failed to build cache: {$e->getMessage()}");
        }
    }

    private function getStoreDriver(string $storeName): string
    {
        return $this->getStoreConfiguration($storeName)->driver;
    }

    private function validateStoreConfiguration(string $storeName): void
    {
        $store = $this->config->getStore($storeName);
        $features = $this->config->getFeatureConfig();

        if ('layered' === $store->driver && empty($store->layers)) {
            throw ConfigurationException::invalidConfiguration($storeName, 'Layered cache must have at least one layer');
        }

        $this->logger->info('Store configuration validated', [
            'store' => $storeName,
            'driver' => $store->driver,
            'monitoring_enabled' => $features->hasMonitoringEnabled(),
        ]);
    }

    private function getStoreConfiguration(string $storeName): StoreConfig
    {
        return $this->config->getStore($storeName);
    }
}
