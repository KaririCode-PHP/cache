<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Service\ValidationService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder for creating a LayeredCacheManager instance.
 *
 * This class follows the Builder pattern to simplify the construction of a complex
 * LayeredCacheManager, which is composed of multiple cache drivers (layers). It
 * orchestrates the creation of each layer's driver via the DriverManager, ensuring
 * that all dependencies, like the central ExpirationProvider, are correctly injected.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredCacheBuilder
{
    /** @var array<int, array{driver: string, config: array<string, mixed>, priority: int}> */
    private array $layers = [];

    private ?ExpirationProvider $expirationProvider = null;
    private ?DriverManager $driverManager = null;
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * Sets the layers to be used in the layered cache.
     *
     * @param array<int, array{driver: string, config: array<string, mixed>, priority: int}> $layers
     */
    public function withLayers(array $layers): self
    {
        $this->layers = $layers;

        return $this;
    }

    /**
     * Sets the expiration provider to be shared across all layers.
     */
    public function withExpirationProvider(ExpirationProvider $provider): self
    {
        $this->expirationProvider = $provider;

        return $this;
    }

    /**
     * Sets the DriverManager to be used for creating layer drivers.
     */
    public function withDriverManager(DriverManager $manager): self
    {
        $this->driverManager = $manager;

        return $this;
    }

    /**
     * Sets the logger to be used by the manager and drivers.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Builds the LayeredCacheManager instance.
     *
     * This method orchestrates the creation of each cache driver for the layers
     * and injects them into the LayeredCacheManager in the correct priority order.
     *
     * @throws ConfigurationException if the configuration is invalid
     */
    public function build(): LayeredCacheManager
    {
        if (empty($this->layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one layer must be configured for LayeredCacheManager.');
        }

        if (null === $this->expirationProvider) {
            throw ConfigurationException::invalidConfiguration('expirationProvider', 'An ExpirationProvider must be set.');
        }

        // Use the provided DriverManager or create a default one.
        $driverManager = $this->driverManager ?? new DriverManager();
        $layeredCacheManager = new LayeredCacheManager($this->logger, new ValidationService());

        // Sort layers by priority (lower number = higher priority/faster cache)
        usort($this->layers, fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));

        foreach ($this->layers as $layerConfig) {
            $driverName = $layerConfig['driver'];
            $driverConfig = $layerConfig['config'] ?? [];
            $priority = $layerConfig['priority'] ?? 100;

            // IMPORTANT: Inject the single, central ExpirationProvider instance into each layer's config.
            // This ensures all layers share the same TTL strategy, which is crucial for consistency.
            $driverConfig['expiration_provider'] = $this->expirationProvider;

            // Create the driver instance for the layer using the DriverManager.
            $driverInstance = $driverManager->createDriver($driverName, $driverConfig);

            // Add the created driver as a layer to the manager.
            $layeredCacheManager->addLayer($driverInstance, $priority);
        }

        return $layeredCacheManager;
    }
}
