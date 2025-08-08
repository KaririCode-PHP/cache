<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\Factory;

use KaririCode\Cache\Config\Adapter\ConfiguratorAdapter;
use KaririCode\Cache\Config\CacheConfiguration;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;

/**
 * Factory for creating configuration providers.
 *
 * This factory maintains backward compatibility while providing
 * a clean interface for creating configuration providers from
 * different sources.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 */
final class ConfigurationFactory
{
    /**
     * Create configuration provider from array.
     */
    public static function fromConfig(array $config): ConfigurationProvider
    {
        return new CacheConfiguration($config);
    }

    /**
     * Create configuration provider from KaririCode Configurator.
     */
    public static function fromConfigurator(
        ConfigurationManager $configurator,
        string $namespace = 'cache'
    ): ConfigurationProvider {
        return ConfiguratorAdapter::fromConfigurator($configurator, $namespace);
    }

    /**
     * Create configuration provider from mixed source (backward compatibility).
     *
     * @param mixed $config Configuration source
     *
     * @deprecated use specific factory methods fromConfig() or fromConfigurator() instead
     */
    public static function create(mixed $config): ConfigurationProvider
    {
        return match (true) {
            is_array($config) => self::fromConfig($config),
            $config instanceof ConfigurationProvider => $config,
            $config instanceof ConfigurationManager => self::fromConfigurator($config),
            default => throw InvalidArgumentException::invalidConfiguration('Invalid configuration type. Expected array, ConfigurationProvider, or ConfigurationManager'),
        };
    }
}
