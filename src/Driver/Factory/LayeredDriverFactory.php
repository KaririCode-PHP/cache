<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\LayeredDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;

/**
 * Factory for creating LayeredDriver with multiple cache layers.
 * Each layer can have different drivers (APCu -> Redis -> Filesystem).
 */
final class LayeredDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateOptions($options);

        // Build ExpirationProvider - CRITICAL: Create before all layers
        $expirationProvider = $this->createExpirationProvider($options, $manager);

        $layers = [];
        foreach ($options['layers'] ?? [] as $layerConfig) {
            $driverOptions = array_merge(
                $layerConfig['config'] ?? [],
                ['expiration_provider' => $expirationProvider]
            );

            $layer = $manager->createDriver($layerConfig['driver'], $driverOptions);
            $priority = $layerConfig['priority'] ?? 100;

            $layers[] = [
                'driver' => $layer,
                'priority' => $priority,
            ];
        }

        // Sort layers by priority (lower number = higher priority)
        usort($layers, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return new LayeredDriver(
            array_column($layers, 'driver'),
            $expirationProvider
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            'layers',
            'expiration_provider',
            'default_ttl',
            'operation_ttls',
            'key_pattern_ttls',
        ];
    }

    public function validateOptions(array $options): bool
    {
        if (!isset($options['layers']) || !is_array($options['layers'])) {
            throw ConfigurationException::invalidConfiguration('layers', 'Layers configuration is required and must be an array');
        }

        if (empty($options['layers'])) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one layer must be configured');
        }

        foreach ($options['layers'] as $index => $layer) {
            if (!is_array($layer)) {
                throw ConfigurationException::invalidConfiguration("layers[{$index}]", 'Layer configuration must be an array');
            }

            if (!isset($layer['driver']) || !is_string($layer['driver'])) {
                throw ConfigurationException::invalidConfiguration("layers[{$index}].driver", 'Layer must have a valid driver name');
            }
        }

        return true;
    }

    private function createExpirationProvider(array $options, DriverManager $manager): ExpirationProvider
    {
        if (isset($options['expiration_provider']) && $options['expiration_provider'] instanceof ExpirationProvider) {
            return $options['expiration_provider'];
        }

        return new ConfigurableExpirationProvider(
            $options['default_ttl'] ?? 3600,
            $options['operation_ttls'] ?? [
                'set' => 3600,
                'setMultiple' => 3600,
                'add' => 3600,
                'increment' => 7200,
                'decrement' => 7200,
                'setWithTags' => 1800,
            ],
            $options['key_pattern_ttls'] ?? [
                'session:*' => 7200,
                'api:*' => 1800,
                'temp:*' => 300,
                'user:*' => 900,
            ]
        );
    }
}
