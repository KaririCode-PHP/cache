<?php

declare(strict_types=1);

/**
 * Bootstrap example for KaririCode\Cache with KaririCode\Configurator integration.
 *
 * This example demonstrates how to initialize and use the cache system
 * with external configuration files in different environments.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KaririCode\Cache\CacheBuilder;
use KaririCode\Cache\CacheManager;
use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Loader\JsonLoader;
use KaririCode\Configurator\Loader\PhpLoader;
use KaririCode\Configurator\Loader\YamlLoader;
use Psr\Log\LoggerInterface;

/**
 * Simple logger implementation for demonstration.
 */
class ConsoleLogger implements LoggerInterface
{
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        echo sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, $message);
        if (!empty($context)) {
            echo 'Context: ' . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
    }
}

/**
 * Initialize KaririCode\Configurator with multiple loaders.
 */
function initializeConfigurator(): Configuration
{
    $config = new Configuration();

    // Register loaders for different file formats
    $config->registerLoader(new PhpLoader());
    $config->registerLoader(new JsonLoader());
    $config->registerLoader(new YamlLoader());

    return $config;
}

/**
 * Load configuration files based on environment.
 */
function loadConfigurationFiles(Configuration $config): void
{
    $configPath = __DIR__ . '/../config';
    if (!is_dir($configPath)) {
        throw new RuntimeException("Configuration directory not found: {$configPath}");
    }

    try {
        // Load base configuration
        $config->load($configPath . '/cache.php');

        // Load environment-specific configuration if it exists
        $envConfigFile = $configPath . '/cache.php';
        if (file_exists($envConfigFile)) {
            $config->load($envConfigFile);
        }

        // You can also load from JSON or YAML
        $jsonConfigFile = $configPath . '/cache.json';
        if (file_exists($jsonConfigFile)) {
            $config->load($jsonConfigFile);
        }

        $yamlConfigFile = $configPath . '/cache.yaml';
        if (file_exists($yamlConfigFile)) {
            $config->load($yamlConfigFile);
        }

        echo "Configuration loaded successfully\n";
    } catch (Exception $e) {
        echo 'Failed to load configuration: ' . $e->getMessage() . "\n";
        throw $e;
    }
}

/**
 * Example 1: Basic usage with configuration files.
 */
function example1_BasicUsage(): void
{
    echo "\n=== Example 1: Basic Usage ===\n";

    // Initialize configurator
    $configurator = initializeConfigurator();

    // // Load configuration files
    loadConfigurationFiles($configurator);

    var_dump($configurator->get('cache.default'));
    // Initialize cache manager
    $logger = new ConsoleLogger();
    $cacheManager = CacheManager::initialize($configurator, $logger);

    // // Get default cache instance
    // $cache = $cacheManager->getDefault();

    // // Use the cache
    // $cache->set('user:123', ['name' => 'John Doe', 'email' => 'john@example.com']);
    // $user = $cache->get('user:123');

    // echo "Cached user: " . json_encode($user) . "\n";

    // // Get metrics
    // $metrics = $cache->getMetrics();
    // echo "Cache metrics: " . json_encode($metrics, JSON_PRETTY_PRINT) . "\n";
}

/**
 * Example 2: Using specific cache stores.
 */
function example2_SpecificStores(): void
{
    echo "\n=== Example 2: Specific Cache Stores ===\n";

    $configurator = initializeConfigurator();
    loadConfigurationFiles($configurator, 'production');

    $cacheManager = CacheManager::initialize($configurator, new ConsoleLogger());

    // Get Redis cache store
    if ($cacheManager->has('redis')) {
        $redisCache = $cacheManager->getStore('redis');
        $redisCache->set('session:abc123', ['user_id' => 456, 'expires' => time() + 3600]);
        echo "Redis cache used successfully\n";
    }

    // Get filesystem cache store
    if ($cacheManager->has('filesystem')) {
        $fileCache = $cacheManager->getStore('filesystem');
        $fileCache->set('config:app', ['debug' => false, 'version' => '1.0.0']);
        echo "Filesystem cache used successfully\n";
    }

    // List all available stores
    echo 'Available stores: ' . implode(', ', $cacheManager->getStoreNames()) . "\n";
}

/**
 * Example 3: Development environment setup.
 */
function example3_DevelopmentSetup(): void
{
    echo "\n=== Example 3: Development Setup ===\n";

    $configurator = initializeConfigurator();
    loadConfigurationFiles($configurator, 'development');

    // Create development-specific cache config
    $cacheConfig = CacheConfig::development($configurator);

    // Initialize manager with development config
    $cacheManager = CacheManager::initializeWithConfig(
        $cacheConfig,
        $configurator,
        new ConsoleLogger()
    );

    // Build development cache with full observability
    $builder = CacheBuilder::fromConfigurator($configurator)
        ->withLogger(new ConsoleLogger());

    $devCache = $builder->buildForDevelopment();

    // Test with some data
    $devCache->set('debug:info', ['env' => 'development', 'debug' => true]);
    $debugInfo = $devCache->get('debug:info');

    echo 'Development cache: ' . json_encode($debugInfo) . "\n";

    // Show detailed metrics for development
    $metrics = $devCache->getMetrics();
    echo 'Development metrics: ' . json_encode($metrics, JSON_PRETTY_PRINT) . "\n";
}

/**
 * Example 4: High-performance production setup.
 */
function example4_ProductionSetup(): void
{
    echo "\n=== Example 4: Production Setup ===\n";

    $configurator = initializeConfigurator();
    loadConfigurationFiles($configurator, 'production');

    $cacheManager = CacheManager::initialize($configurator, new ConsoleLogger());

    // Get layered cache for production
    $productionCache = $cacheManager->getDefault();

    // Test layered caching
    $productionCache->set('hot:data', ['frequently' => 'accessed', 'data' => range(1, 100)]);

    // This should hit the fastest layer
    $hotData = $productionCache->get('hot:data');
    echo 'Hot data retrieved: ' . (is_array($hotData) ? count($hotData) . ' items' : 'null') . "\n";

    // Use cache with tags for organized invalidation
    if (method_exists($productionCache, 'setWithTags')) {
        $productionCache->setWithTags('product:123', ['name' => 'Widget', 'price' => 99.99], 3600, ['products', 'catalog']);
        $productionCache->setWithTags('product:456', ['name' => 'Gadget', 'price' => 149.99], 3600, ['products', 'catalog']);

        // Invalidate all products
        $productionCache->invalidateTags(['products']);
        echo "Product cache invalidated via tags\n";
    }
}

/**
 * Example 5: Custom cache builder configuration.
 */
function example5_CustomBuilder(): void
{
    echo "\n=== Example 5: Custom Builder ===\n";

    $configurator = initializeConfigurator();
    loadConfigurationFiles($configurator, 'production');

    $cacheConfig = new CacheConfig($configurator);

    // Build custom cache with specific features
    $customCache = CacheBuilder::create($cacheConfig)
        ->withApcu(5)                          // Fast local cache
        ->withRedis(['host' => 'localhost'], 10) // Distributed cache
        ->withFilesystem('/tmp/custom-cache', 90) // Persistent fallback
        ->withFullObservability(true)          // Enable all monitoring
        ->withStrictKeyValidation(true)        // Validate cache keys
        ->withSlidingExpiration(true)          // Extend TTL on access
        ->withLogger(new ConsoleLogger())
        ->build();

    // Test the custom cache
    $customCache->set('custom:test', ['builder' => 'custom', 'features' => 'all']);
    $result = $customCache->get('custom:test');

    echo 'Custom cache result: ' . json_encode($result) . "\n";

    // Test key validation
    try {
        $customCache->set('invalid key with spaces!', 'test');
    } catch (Exception $e) {
        echo 'Key validation caught invalid key: ' . $e->getMessage() . "\n";
    }
}

/**
 * Example 6: Configuration validation and management.
 */
function example6_ConfigurationManagement(): void
{
    echo "\n=== Example 6: Configuration Management ===\n";

    $configurator = initializeConfigurator();
    loadConfigurationFiles($configurator, 'production');

    $cacheConfig = new CacheConfig($configurator);

    // Validate configuration
    $errors = $cacheConfig->validate();
    if (empty($errors)) {
        echo "Configuration is valid\n";
    } else {
        echo "Configuration errors:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }

    // Show configuration details
    echo 'Default store: ' . $cacheConfig->getDefaultStore() . "\n";
    echo 'Serializer: ' . $cacheConfig->getSerializer() . "\n";
    echo 'Default TTL: ' . $cacheConfig->getDefaultTtl() . " seconds\n";
    echo "Features enabled:\n";

    $features = $cacheConfig->getFeatures();
    foreach ($features as $feature => $enabled) {
        echo "  - {$feature}: " . ($enabled ? 'YES' : 'NO') . "\n";
    }

    // Initialize manager and show stats
    $cacheManager = CacheManager::initializeWithConfig(
        $cacheConfig,
        $configurator,
        new ConsoleLogger()
    );

    $stats = $cacheManager->getManagerStats();
    echo 'Manager stats: ' . json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}

/**
 * Example 7: Environment variable support.
 */
function example7_EnvironmentVariables(): void
{
    echo "\n=== Example 7: Environment Variables ===\n";

    // Set some environment variables for testing
    $_ENV['REDIS_HOST'] = 'redis.example.com';
    $_ENV['REDIS_PORT'] = '6380';
    $_ENV['CACHE_DEFAULT_TTL'] = '7200';
    $_ENV['CACHE_METRICS'] = 'true';

    $configurator = initializeConfigurator();
    loadConfigurationFiles($configurator, 'production');

    $cacheConfig = new CacheConfig($configurator);

    echo 'Redis host from env: ' . $cacheConfig->get('stores.redis.config.host') . "\n";
    echo 'Redis port from env: ' . $cacheConfig->get('stores.redis.config.port') . "\n";
    echo 'Default TTL from env: ' . $cacheConfig->getDefaultTtl() . "\n";
    echo 'Metrics enabled from env: ' . ($cacheConfig->isMetricsEnabled() ? 'YES' : 'NO') . "\n";
}

/**
 * Run all examples.
 */
function runAllExamples(): void
{
    try {
        echo "KaririCode\\Cache with KaririCode\\Configurator - Examples\n";
        echo str_repeat('=', 60) . "\n";

        example1_BasicUsage();
        // example2_SpecificStores();
        // example3_DevelopmentSetup();
        // example4_ProductionSetup();
        // example5_CustomBuilder();
        // example6_ConfigurationManagement();
        // example7_EnvironmentVariables();

        // echo "\n" . str_repeat('=', 60) . "\n";
        // echo "All examples completed successfully!\n";
    } catch (Exception $e) {
        echo 'Example failed: ' . $e->getMessage() . "\n";
        echo 'Trace: ' . $e->getTraceAsString() . "\n";
    }
}

// Run examples if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    runAllExamples();
}
