<?php

// ./tests/application.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap\CacheBootstrap;
use KaririCode\Logging\LoggerBuilder;
use Monolog\Handler\StreamHandler;

// Create logger (optional)
$logger = new LoggerBuilder('cache');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Bootstrap the cache system
$bootstrap = new CacheBootstrap($logger);
$cacheManager = $bootstrap->createCacheManager();

// Use different stores
$redisCache = $cacheManager->store('redis');
$layeredCache = $cacheManager->store('layered'); // Default from config
$fileCache = $cacheManager->store('filesystem');

// Basic operations
$cacheManager->set('user:123', ['name' => 'John', 'email' => 'john@example.com'], 3600);
$user = $cacheManager->get('user:123');

// Advanced operations (if driver supports)
$redisCache->setWithTags('product:456', ['name' => 'Laptop'], ['products', 'electronics'], 1800);
$redisCache->invalidateTags(['products']); // Invalidate all products

// Atomic operations
$counter = $redisCache->increment('page_views', 1);

// Health checks
$healthChecks = $bootstrap->getCacheConfig()->getHealthChecks();
if ($healthChecks->enabled) {
    echo "Health checks are enabled with {$healthChecks->interval}s interval\n";
}

// Configuration inspection
$config = $bootstrap->getCacheConfig();
echo 'Default store: ' . $config->getDefaultStore() . "\n";
echo 'Available stores: ' . implode(', ', array_keys($config->getStores())) . "\n";
echo 'Default serializer: ' . $config->getDefaultSerializer() . "\n";

// Feature flags
$features = $config->getFeatures();
if ($features->stampedeProtection) {
    echo "Stampede protection is enabled\n";
}

echo "Cache system initialized successfully!\n";
