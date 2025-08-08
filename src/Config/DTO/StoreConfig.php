<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO to represent a cache store's configuration.
 *
 * This Value Object encapsulates the settings for a single cache store,
 * such as its driver type and driver-specific configurations.
 */
final class StoreConfig implements ImmutableValue
{
    /**
     * @param string $driver The cache driver to be used (e.g., 'redis', 'filesystem').
     * @param array<string, mixed> $config the specific configuration for the driver
     * @param list<LayerConfig> $layers a list of layer configurations for a 'layered' driver
     */
    private function __construct(
        public readonly string $driver,
        public readonly array $config,
        public readonly array $layers
    ) {
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $data the raw configuration array for the store
     *
     * @throws ConfigurationException if the configuration data is invalid
     */
    public static function fromConfig(array $data): self
    {
        $driver = $data['driver'] ?? null;
        if (!is_string($driver) || empty($driver)) {
            throw ConfigurationException::invalidConfiguration('store', 'The "driver" key is mandatory and must be a non-empty string.');
        }

        $config = (isset($data['config']) && is_array($data['config'])) ? $data['config'] : [];
        $layersData = (isset($config['layers']) && is_array($config['layers'])) ? $config['layers'] : [];

        $layers = array_map(function ($layerData) {
            if (!is_array($layerData) || empty($layerData['driver']) || !is_string($layerData['driver'])) {
                throw ConfigurationException::invalidConfiguration('store.layers', 'Each layer must be an array with a valid "driver" key.');
            }

            return new LayerConfig(
                driver: $layerData['driver'],
                config: (isset($layerData['config']) && is_array($layerData['config'])) ? $layerData['config'] : [],
                priority: (int) ($layerData['priority'] ?? 100)
            );
        }, $layersData);

        return new self($driver, $config, $layers);
    }
}
