<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Contract\CacheConfig;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Util\Dotenv;

class Configuration implements CacheConfig
{
    private array $config;
    private Dotenv $dotenv;

    public function __construct(string $configPath, string $envPath)
    {
        $this->dotenv = new Dotenv($envPath);
        $this->loadConfiguration($configPath);
    }

    private function loadConfiguration(string $path): void
    {
        if (!file_exists($path)) {
            throw new ConfigurationException("Configuration file not found: $path");
        }

        $this->config = require $path;

        if (!isset($this->config['default_driver'])) {
            throw new ConfigurationException('Default driver not specified in configuration');
        }
    }

    public function getDrivers(): array
    {
        return array_keys($this->config['drivers']);
    }

    public function getDefaultDriver(): string
    {
        return $this->config['default_driver'];
    }

    public function getDriverConfig(string $driver): array
    {
        if (!isset($this->config['drivers'][$driver])) {
            throw new ConfigurationException("Driver configuration not found: $driver");
        }

        $driverConfig = $this->config['drivers'][$driver];

        // Resolve environment variables
        array_walk_recursive($driverConfig, function (&$value, $key) {
            if (is_string($value) && 0 === strpos($value, 'env:')) {
                $envKey = substr($value, 4);
                $value = $this->dotenv->get($envKey);
            }
        });

        return $driverConfig;
    }

    public function getDefaultTtl(): int
    {
        return $this->config['default_ttl'] ?? 3600;
    }
}
