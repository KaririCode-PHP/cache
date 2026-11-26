<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\LayeredDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\Logging\Logger;

/**
 * Factory for creating a fully-configured LayeredDriver.
 *
 * This factory constructs a layered cache driver, which is composed of multiple
 * underlying cache drivers. It orchestrates the creation of each layer's driver,
 * ensuring that the centrally-configured ExpirationProvider is propagated to all
 * of them for consistent TTL behavior.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredDriverFactory extends AbstractDriverFactory
{
    /**
     * Creates the LayeredDriver instance.
     *
     * This method iterates through the layer configurations, uses the DriverManager
     * to create an instance of each sub-driver, and then assembles them into
     * a single LayeredDriver, configured with its specific behavior DTO.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for creating layer instances
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param Logger $logger the logger instance
     *
     * @return CacheDriver the fully configured LayeredDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        Logger $logger
    ): CacheDriver {
        // 1. Create the specific configuration DTO for the LayeredDriver's behavior.
        $layeredConfig = LayeredDriverConfig::fromArray($options);

        $layers = [];
        foreach ($options['layers'] ?? [] as $layerConfig) {
            $this->validateLayerConfig($layerConfig);

            // 2. IMPORTANT: Inject the shared ExpirationProvider into each sub-driver's config.
            $driverOptions = array_merge(
                $layerConfig['config'] ?? [],
                ['expiration_provider' => $expirationProvider]
            );

            $layerDriver = $manager->createDriver($layerConfig['driver'], $driverOptions);

            $layers[] = [
                'driver' => $layerDriver,
                'priority' => $layerConfig['priority'] ?? 100,
            ];
        }

        // 3. Sort layers by priority (lower number = higher priority/faster cache).
        usort($layers, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        // 4. Instantiate the LayeredDriver with the list of drivers and its config DTO.
        return new LayeredDriver(
            array_column($layers, 'driver'),
            $layeredConfig,
            $logger
        );
    }

    protected function getDriverName(): string
    {
        return 'layered';
    }

    protected function getRequiredOptions(): array
    {
        return ['layers'];
    }

    protected function getOptionalOptions(): array
    {
        return [
            'promotion_enabled',
            'promotion_threshold',
            'circuit_breaker_enabled',
            'max_failures',
            'circuit_timeout',
        ];
    }

    protected function doValidateOptions(array $options): bool
    {
        if (empty($options['layers']) || !is_array($options['layers'])) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one layer must be configured in an array for a layered cache.');
        }

        foreach ($options['layers'] as $index => $layer) {
            try {
                $this->validateLayerConfig($layer);
            } catch (ConfigurationException $e) {
                throw ConfigurationException::invalidConfiguration("layers[{$index}]", $e->getMessage());
            }
        }

        // Also validate the layered driver's own settings by creating the DTO.
        LayeredDriverConfig::fromArray($options);

        return true;
    }

    private function validateLayerConfig($layer): void
    {
        if (!is_array($layer)) {
            throw ConfigurationException::invalidConfiguration('layer', 'Layer configuration must be an array.');
        }
        if (empty($layer['driver']) || !is_string($layer['driver'])) {
            throw ConfigurationException::invalidConfiguration('layer.driver', 'Each layer must specify a valid driver name.');
        }
    }
}
