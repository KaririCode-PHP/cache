<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Configuration;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\HealthCheckConfig;
use KaririCode\Cache\Config\DTO\LoggingConfig;
use KaririCode\Cache\Config\DTO\MetricsConfig;
use KaririCode\Cache\Config\DTO\OptimizationConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Unified configuration interface following Interface Segregation Principle.
 *
 * This interface defines the contract for all configuration providers,
 * ensuring consistency across different configuration sources.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface ConfigurationProvider
{
    /**
     * Get the default store name.
     */
    public function getDefaultStore(): string;

    /**
     * Get all configured stores.
     *
     * @return array<string, StoreConfig>
     */
    public function getStores(): array;

    /**
     * Get a specific store configuration.
     *
     * @throws ConfigurationException
     */
    public function getStore(string $name): StoreConfig;

    /**
     * Check if a store exists.
     */
    public function hasStore(string $name): bool;

    /**
     * Get the default serializer name.
     */
    public function getDefaultSerializer(): string;

    /**
     * Get all configured serializers.
     *
     * @return array<string, mixed>
     */
    public function getSerializers(): array;

    /**
     * Get feature configuration.
     */
    public function getFeatures(): FeatureConfig;

    /**
     * Get validation configuration.
     */
    public function getValidation(): ValidationConfig;

    /**
     * Get metrics configuration.
     */
    public function getMetrics(): MetricsConfig;

    /**
     * Get logging configuration.
     */
    public function getLogging(): LoggingConfig;

    /**
     * Get health check configuration.
     */
    public function getHealthChecks(): HealthCheckConfig;

    /**
     * Get expiration provider configuration.
     */
    public function getExpirationProvider(): ExpirationProviderConfig;

    /**
     * Get optimization configuration.
     */
    public function getOptimizations(): OptimizationConfig;
}
