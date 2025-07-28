<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO to represent a cache store's configuration.
 */
final class StoreConfig implements ImmutableValue
{
    /** @var string The cache driver to be used (e.g., 'redis', 'filesystem'). */
    public readonly string $driver;

    /** @var array<string, mixed> The specific configuration for the driver. */
    public readonly array $config;

    /** @var list<array{driver: string, config: array<string, mixed>, priority: int}> The layers for a 'layered' driver. */
    public readonly array $layers;

    /**
     * @param array<string, mixed> $data the raw configuration data for the store
     *
     * @throws ConfigurationException if the configuration data is invalid
     */
    public function __construct(array $data)
    {
        $driver = $data['driver'] ?? null;
        if (!is_string($driver) || empty($driver)) {
            throw ConfigurationException::invalidConfiguration('store', 'The "driver" key is mandatory and must be a non-empty string.');
        }

        $this->driver = $driver;
        $this->config = (isset($data['config']) && is_array($data['config'])) ? $data['config'] : [];

        $validatedLayers = [];
        if (isset($data['layers']) && is_array($data['layers'])) {
            foreach ($data['layers'] as $index => $layerData) {
                if (!is_array($layerData)) {
                    throw ConfigurationException::invalidConfiguration("store.layers[{$index}]", 'The layer configuration must be an array.');
                }
                if (empty($layerData['driver']) || !is_string($layerData['driver'])) {
                    throw ConfigurationException::invalidConfiguration("store.layers[{$index}]", 'The "driver" key for a layer is mandatory and must be a non-empty string.');
                }

                $validatedLayers[] = [
                    'driver' => $layerData['driver'],
                    'config' => (isset($layerData['config']) && is_array($layerData['config'])) ? $layerData['config'] : [],
                    'priority' => (isset($layerData['priority']) && is_int($layerData['priority'])) ? $layerData['priority'] : 100,
                ];
            }
        }

        /** @var list<array{driver: string, config: array<string, mixed>, priority: int}> $validatedLayers */
        $this->layers = $validatedLayers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'config' => $this->config,
            'layers' => $this->layers,
        ];
    }
}
