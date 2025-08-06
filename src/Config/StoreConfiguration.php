<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * Store Configuration Value Object.
 *
 * Represents the configuration for a single cache store.
 * This is an immutable value object that encapsulates all
 * settings for a specific cache driver instance.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class StoreConfiguration implements ImmutableValue
{
    private readonly string $name;
    private readonly string $driver;
    private readonly array $config;
    private readonly array $layers;

    /**
     * @param string $name Store name
     * @param array<string, mixed> $config Store configuration
     */
    public function __construct(string $name, array $config)
    {
        $this->validateConfig($config);

        $this->name = $name;
        $this->driver = $config['driver'];
        $this->config = $config['config'] ?? [];
        $this->layers = $config['layers'] ?? [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getLayers(): array
    {
        return $this->layers;
    }

    public function isLayered(): bool
    {
        return 'layered' === $this->driver && !empty($this->layers);
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function hasOption(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * Get all configuration options.
     */
    public function getOptions(): array
    {
        return $this->config;
    }

    private function validateConfig(array $config): void
    {
        if (!isset($config['driver'])) {
            throw ConfigurationException::invalidConfiguration('driver', 'Driver must be specified for cache store');
        }

        if (!is_string($config['driver']) || empty($config['driver'])) {
            throw ConfigurationException::invalidConfiguration('driver', 'Driver must be a non-empty string');
        }

        // Validate layered configuration
        if ('layered' === $config['driver']) {
            if (!isset($config['layers']) || !is_array($config['layers'])) {
                throw ConfigurationException::invalidConfiguration('layers', 'Layered cache must have layers configuration');
            }

            if (empty($config['layers'])) {
                throw ConfigurationException::invalidConfiguration('layers', 'Layered cache must have at least one layer');
            }

            $this->validateLayers($config['layers']);
        }

        // Validate config is array if present
        if (isset($config['config']) && !is_array($config['config'])) {
            throw ConfigurationException::invalidConfiguration('config', 'Store config must be an array');
        }
    }

    private function validateLayers(array $layers): void
    {
        foreach ($layers as $index => $layer) {
            if (!is_array($layer)) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}", 'Layer configuration must be an array');
            }

            if (!isset($layer['driver'])) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}.driver", 'Layer driver must be specified');
            }

            if (!is_string($layer['driver']) || empty($layer['driver'])) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}.driver", 'Layer driver must be a non-empty string');
            }

            // Validate priority if present
            if (isset($layer['priority']) && (!is_int($layer['priority']) || $layer['priority'] < 0)) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}.priority", 'Layer priority must be a non-negative integer');
            }
        }
    }
}
