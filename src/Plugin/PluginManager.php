<?php

declare(strict_types=1);

namespace KaririCode\Cache\Plugin;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheComponentPlugin;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CachePlugin;
use KaririCode\Cache\Exception\ConfigurationException;

class PluginManager
{
    private DriverManager $driverManager;
    private array $pluginConfigurations = [];
    private ?string $configPath;

    public function __construct(DriverManager $driverManager, ?string $configPath = null)
    {
        $this->driverManager = $driverManager;
        $this->configPath = $configPath;
        $this->loadConfigurations();
    }

    public function registerPlugins(array $pluginConfigs): void
    {
        foreach ($pluginConfigs as $pluginClass => $config) {
            if ($config['enabled'] ?? false) {
                $this->registerPlugin($pluginClass, $config);
            }
        }
    }

    private function registerPlugin(string $pluginClass, array $config): void
    {
        if (!is_subclass_of($pluginClass, CachePlugin::class) && !is_subclass_of($pluginClass, CacheComponentPlugin::class)) {
            throw new ConfigurationException("Invalid plugin class: $pluginClass");
        }

        $this->pluginConfigurations[$pluginClass] = $config;

        // Instantiate the plugin with the configuration array
        $plugin = new $pluginClass($config);
        $plugin->register($this->driverManager);

        if ($plugin instanceof CachePlugin && method_exists($plugin, 'getFactoryClass')) {
            $factoryClass = $plugin->getFactoryClass();
            if (null !== $factoryClass && is_subclass_of($factoryClass, CacheDriverFactory::class)) {
                $this->driverManager->addFactory($plugin->getDriverName(), $factoryClass);
                $this->driverManager->setDriverOptions($plugin->getDriverName(), $config['options'] ?? []);
            } else {
                throw new ConfigurationException("Invalid factory class for plugin: $pluginClass");
            }
        }
    }

    public function getPluginConfiguration(string $pluginName): ?array
    {
        return $this->pluginConfigurations[$pluginName] ?? null;
    }

    private function loadConfigurations(): void
    {
        if ($this->configPath && file_exists($this->configPath)) {
            $configs = require $this->configPath;
            if (is_array($configs) && isset($configs['plugins']) && is_array($configs['plugins'])) {
                $this->pluginConfigurations = array_merge($this->pluginConfigurations, $configs['plugins']);
            } else {
                throw new ConfigurationException('Invalid plugin configuration file structure');
            }
        }
    }
}
