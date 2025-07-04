<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\CacheSettings;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheConfig;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Plugin\PluginManager;

class CacheBuilder
{
    private DriverManager $driverManager;
    private PluginManager $pluginManager;

    public function __construct(?string $configPath = null)
    {
        $this->driverManager = new DriverManager();
        $this->pluginManager = new PluginManager($this->driverManager, $configPath);
    }

    public function build(array $config): Cache
    {
        $cacheSettings = new CacheSettings($config);
        $this->pluginManager->registerPlugins($cacheSettings->getPlugins());

        $layeredCache = $this->createLayeredCache($cacheSettings);
        $transactionManager = $this->driverManager->getTransactionManager();
        $serializer = $this->driverManager->getSerializer();

        return new Cache($layeredCache, $transactionManager, $serializer);
    }

    private function createLayeredCache(CacheConfig $cacheSettings): LayeredCache
    {
        $layeredCache = new LayeredCacheManager();
        foreach ($cacheSettings->getLayerConfigurations() as $priority => $layerConfig) {
            $driver = $this->driverManager->createDriver(
                $layerConfig['driver'],
                $layerConfig['options']
            );
            $pool = new CachePoolManager($driver);
            $layeredCache->addLayer($pool, $priority);
        }

        return $layeredCache;
    }
}
