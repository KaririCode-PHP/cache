<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
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
    /** @var array<string, Cache> */
    private array $caches = [];

    /** @var array<string, CacheBuilder> */
    private array $builders = [];

    public function __construct(
        private CacheConfig $config,
        private ConfigurationManager $configurator,
        private LoggerInterface $logger
    ) {
    }

    public function get(?string $name = null): Cache
    {
        $storeName = $name ?? $this->config->getDefaultStore();

        if (!isset($this->caches[$storeName])) {
            $this->caches[$storeName] = $this->buildCache($storeName);
        }

        return $this->caches[$storeName];
    }

    public function getStore(string $storeName): Cache
    {
        return $this->get($storeName);
    }

    public function getDefault(): Cache
    {
        return $this->get();
    }

    public function createWithBuilder(string $name, CacheBuilder $builder): Cache
    {
        $cache = $builder->build();
        $this->caches[$name] = $cache;

        $this->logger->info('Custom cache instance created', [
            'store' => $name,
        ]);

        return $cache;
    }

    public function getBuilder(string $storeName): CacheBuilder
    {
        if (!isset($this->builders[$storeName])) {
            $this->builders[$storeName] = $this->createBuilder($storeName);
        }

        return clone $this->builders[$storeName];
    }

    public function createBuilder(string $storeName): CacheBuilder
    {
        if (!$this->has($storeName)) {
            throw ConfigurationException::missingDriver($storeName);
        }

        $builder = new CacheBuilder($this->config, $this->logger);
        $builder->configureFromStore($storeName);

        return $builder;
    }

    public function createCache(string $storeName): Cache
    {
        $builder = $this->createBuilder($storeName);

        return $builder->build();
    }

    public function has(string $name): bool
    {
        return $this->config->has("stores.{$name}");
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->caches[$name]);
    }

    /**
     * @return list<string> list of store names
     */
    public function getStoreNames(): array
    {
        return $this->config->getStoreNames();
    }

    /**
     * @return array<string, Cache>
     */
    public function getLoadedCaches(): array
    {
        return $this->caches;
    }

    public function forget(string $name): bool
    {
        if (isset($this->caches[$name])) {
            unset($this->caches[$name], $this->builders[$name]);

            $this->logger->info('Cache instance forgotten', [
                'store' => $name,
            ]);

            return true;
        }

        return false;
    }

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
     * @param array<string, mixed> $data
     *
     * @return array<string, array{success: bool, error?: string}>
     */
    public function warmAll(array $data, \DateInterval|int|null $ttl = null): array
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
     * @return array<string, array<string, mixed>>
     */
    public function getAllMetrics(): array
    {
        $metrics = [];

        foreach ($this->caches as $name => $cache) {
            try {
                $metrics[$name] = $cache->getMetrics()->toArray();
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
     * @return array<string, mixed>
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
     * @return list<string> list of validation errors
     */
    public function validateConfiguration(): array
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

    public function reloadConfiguration(): void
    {
        $this->logger->info('Reloading cache configuration');
        $this->caches = [];
        $this->builders = [];

        $errors = $this->validateConfiguration();
        if (!empty($errors)) {
            $this->logger->warning('Configuration validation errors after reload', [
                'errors' => $errors,
            ]);
        }

        $this->logger->info('Cache configuration reloaded successfully');
    }

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

    private function getStoreDriver(string $storeName): string
    {
        return $this->getStoreConfiguration($storeName)->driver;
    }

    private function validateStoreConfiguration(string $storeName): void
    {
        $store = $this->config->getStore($storeName);
        $features = $this->config->getFeatureConfig();

        if ('layered' === $store->driver) {
            if (empty($store->layers)) {
                throw ConfigurationException::invalidConfiguration($storeName, 'Layered cache must have at least one layer');
            }
        }

        // Validação baseada em features
        $this->validateFeatureDependencies($features);

        $this->logger->info('Store configuration validated', [
            'store' => $storeName,
            'driver' => $store->driver,
            'features' => $features->toArray(),
            'monitoring_enabled' => $features->hasMonitoringEnabled(),
        ]);
    }

    private function validateFeatureDependencies(FeatureConfig $features): void
    {
        if ($features->psr14EventsEnabled && !$features->eventsEnabled) {
            throw ConfigurationException::invalidConfiguration('features', 'PSR-14 events require basic events to be enabled');
        }

        if ($features->slidingExpiration && !$features->metricsEnabled) {
            $this->logger->warning('Sliding expiration works better with metrics enabled');
        }
    }

    private function getStoreConfiguration(string $storeName): StoreConfig
    {
        return $this->config->getStore($storeName);
    }
}
