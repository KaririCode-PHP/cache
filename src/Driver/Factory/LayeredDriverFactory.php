<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\LayeredDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating a LayeredDriver.
 *
 * This factory constructs a layered (or hierarchical) cache driver, which is
 * composed of multiple underlying cache drivers. It orchestrates the creation of
 * each layer's driver, ensuring that the centrally-configured ExpirationProvider
 * is propagated to all of them for consistent TTL behavior.
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
     * a single LayeredDriver.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for creating layer instances
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param LoggerInterface $logger the logger instance
     *
     * @return CacheDriver the fully configured LayeredDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        LoggerInterface $logger
    ): CacheDriver {
        $layers = [];
        foreach ($options['layers'] ?? [] as $layerConfig) {
            // IMPORTANT: Inject the received ExpirationProvider into each layer's configuration.
            // This ensures all layers share the exact same TTL strategy.
            $driverOptions = array_merge(
                $layerConfig['config'] ?? [],
                ['expiration_provider' => $expirationProvider]
            );

            $layerDriver = $manager->createDriver($layerConfig['driver'], $driverOptions);
            $priority = $layerConfig['priority'] ?? 100;

            $layers[] = [
                'driver' => $layerDriver,
                'priority' => $priority,
            ];
        }

        // Sort layers by priority (lower number = higher priority/faster cache)
        usort($layers, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return new LayeredDriver(
            array_column($layers, 'driver'),
            $expirationProvider
        );
    }

    /**
     * Returns the unique name of the driver.
     *
     * @return string the driver's name
     */
    protected function getDriverName(): string
    {
        return 'layered';
    }

    /**
     * Specifies the configuration options required for this driver.
     *
     * @return array<string> a list of required option keys
     */
    protected function getRequiredOptions(): array
    {
        return ['layers'];
    }

    /**
     * Performs driver-specific validation of the provided options.
     *
     * @param array<string, mixed> $options the options to validate
     *
     * @throws ConfigurationException if the configuration is invalid
     *
     * @return bool true if the options are valid
     */
    protected function doValidateOptions(array $options): bool
    {
        if (empty($options['layers'])) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one layer must be configured for a layered cache');
        }

        foreach ($options['layers'] as $index => $layer) {
            if (!is_array($layer)) {
                throw ConfigurationException::invalidConfiguration("layers[{$index}]", 'Layer configuration must be an array');
            }

            if (empty($layer['driver']) || !is_string($layer['driver'])) {
                throw ConfigurationException::invalidConfiguration("layers[{$index}].driver", 'Each layer must specify a valid driver name');
            }
        }

        return true;
    }
}
