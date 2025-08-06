<?php

declare(strict_types=1);

namespace App\Bootstrap;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Service\CacheManager;
use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Loader\JsonLoader;
use KaririCode\Configurator\Loader\PhpLoader;
use KaririCode\Configurator\Loader\YamlLoader;
use KaririCode\Dotenv\DotenvFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Complete Cache Bootstrap following SOLID and Clean Code principles.
 *
 * This class demonstrates the proper way to bootstrap the cache system
 * with KaririCode\Configurator integration, supporting multiple configuration
 * formats and providing a clean, testable setup.
 *
 * Design Patterns:
 * - Builder Pattern: Builds the cache system step by step
 * - Factory Pattern: Creates configured instances
 * - Dependency Injection: Allows logger injection
 *
 * @category  Bootstrap
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class CacheBootstrap
{
    private Configuration $configurator;
    private CacheConfig $cacheConfig;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null, ?string $basePath = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $basePath = $basePath ?? dirname(__DIR__, 2);

        $this->loadEnvironment($basePath);
        $this->setupConfigurator();
        $this->loadConfigurations($basePath);
    }

    /**
     * Create fully configured CacheManager.
     */
    public function createCacheManager(): CacheManager
    {
        return new CacheManager($this->cacheConfig, $this->logger);
    }

    /**
     * Get cache configuration for inspection.
     */
    public function getCacheConfig(): CacheConfig
    {
        return $this->cacheConfig;
    }

    /**
     * Get configurator instance.
     */
    public function getConfigurator(): Configuration
    {
        return $this->configurator;
    }

    /**
     * Load environment variables.
     */
    private function loadEnvironment(string $basePath): void
    {
        try {
            $dotenv = DotenvFactory::create($basePath);
            $dotenv->load();

            $this->logger->debug('Environment variables loaded', [
                'path' => $basePath . '/.env',
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load .env file', [
                'error' => $e->getMessage(),
                'path' => $basePath . '/.env',
            ]);
        }
    }

    /**
     * Setup configuration manager with loaders.
     */
    private function setupConfigurator(): void
    {
        $this->configurator = new Configuration();

        // Register all available loaders
        $this->configurator->registerLoader(new PhpLoader());
        $this->configurator->registerLoader(new JsonLoader());
        $this->configurator->registerLoader(new YamlLoader());

        $this->logger->debug('Configuration loaders registered', [
            'loaders' => ['php', 'json', 'yaml'],
        ]);
    }

    /**
     * Load cache configuration.
     */
    private function loadConfigurations(string $basePath): void
    {
        $configPath = $basePath . '/config/cache.php';

        if (!file_exists($configPath)) {
            throw new \RuntimeException("Cache configuration file not found: {$configPath}");
        }

        $this->configurator->load($configPath);
        $this->cacheConfig = CacheConfig::fromConfigurator($this->configurator);

        $this->logger->info('Cache configuration loaded', [
            'config_file' => $configPath,
            'default_store' => $this->cacheConfig->getDefaultStore(),
            'stores_count' => count($this->cacheConfig->getStores()),
        ]);
    }
}
