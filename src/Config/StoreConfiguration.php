<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * Store Configuration Value Object.
 *
 * Represents the configuration for a single cache store. This is an immutable
 * value object that encapsulates all settings for a specific cache driver instance,
 * including specialized configurations for layered drivers.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class StoreConfiguration implements ImmutableValue
{
    private readonly string $name;
    private readonly string $driver;
    private readonly array $config;
    private readonly array $layers;
    private readonly ?LayeredDriverConfig $layeredConfig;

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

        if ('layered' === $this->driver) {
            $this->layers = $this->config['layers'] ?? [];
            $this->layeredConfig = new LayeredDriverConfig($this->config);
        } else {
            $this->layers = [];
            $this->layeredConfig = null;
        }
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
        // For non-layered drivers, this returns all their specific settings.
        // For layered drivers, it contains settings like promotion, etc.
        return $this->config;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLayers(): array
    {
        return $this->layers;
    }

    /**
     * Returns the specific configuration DTO for a layered driver.
     */
    public function getLayeredConfig(): ?LayeredDriverConfig
    {
        return $this->layeredConfig;
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

    private function validateConfig(array $config): void
    {
        if (!isset($config['driver']) || !is_string($config['driver']) || empty($config['driver'])) {
            throw ConfigurationException::invalidConfiguration('driver', 'Driver must be specified for cache store and must be a non-empty string.');
        }

        if ('layered' === $config['driver']) {
            if (!isset($config['config']['layers']) || !is_array($config['config']['layers']) || empty($config['config']['layers'])) {
                throw ConfigurationException::invalidConfiguration('layers', 'Layered cache must have a non-empty "layers" array inside its "config".');
            }
            $this->validateLayers($config['config']['layers']);
        }
    }

    private function validateLayers(array $layers): void
    {
        foreach ($layers as $index => $layer) {
            if (!is_array($layer)) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}", 'Layer configuration must be an array.');
            }
            if (!isset($layer['driver']) || !is_string($layer['driver']) || empty($layer['driver'])) {
                throw ConfigurationException::invalidConfiguration("layers.{$index}.driver", 'Layer driver must be specified and be a non-empty string.');
            }
        }
    }
}
