<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\DriverManager as DriverManagerContract;
use KaririCode\Cache\Driver\Factory\ApcuDriverFactory;
use KaririCode\Cache\Driver\Factory\ArrayDriverFactory;
use KaririCode\Cache\Driver\Factory\FilesystemDriverFactory;
use KaririCode\Cache\Driver\Factory\LayeredDriverFactory;
use KaririCode\Cache\Driver\Factory\MemcachedDriverFactory;
use KaririCode\Cache\Driver\Factory\RedisDriverFactory;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Serializer\NativeSerializer;

/**
 * Driver Manager - Factory Pattern for Cache Drivers.
 *
 * This class manages the creation and configuration of cache drivers,
 * maintaining a registry of driver factories and providing a unified
 * interface for driver instantiation.
 *
 * Design Patterns:
 * - Factory Pattern: Creates drivers through registered factories
 * - Registry Pattern: Maintains driver factory registry
 * - Strategy Pattern: Different drivers for different needs
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
final class DriverManager implements DriverManagerContract
{
    /** @var array<string, class-string<CacheDriverFactory>> */
    private array $factories = [];

    /** @var array<string, array<string, mixed>> */
    private array $driverOptions = [];

    private ?CacheSerializer $serializer = null;

    public function __construct()
    {
        $this->serializer = new NativeSerializer();
        $this->registerDefaultFactories();
    }

    public function addFactory(string $driver, string $factoryClass): void
    {
        if (!is_subclass_of($factoryClass, CacheDriverFactory::class)) {
            throw ConfigurationException::invalidFactory($driver, $factoryClass, CacheDriverFactory::class);
        }

        $this->factories[$driver] = $factoryClass;
    }

    public function setDriverOptions(string $driver, array $options): void
    {
        $this->driverOptions[$driver] = $options;
    }

    public function getDriverOptions(string $driver): array
    {
        return $this->driverOptions[$driver] ?? [];
    }

    public function createDriver(string $driver, array $options = []): CacheDriver
    {
        if (!isset($this->factories[$driver])) {
            throw ConfigurationException::missingDriver($driver);
        }

        $factoryClass = $this->factories[$driver];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        $mergedOptions = array_merge($this->getDriverOptions($driver), $options);

        if (!$factory->validateOptions($mergedOptions)) {
            throw ConfigurationException::invalidConfiguration($driver, 'Invalid driver options');
        }

        return $factory->createDriver($mergedOptions, $this);
    }

    public function setSerializer(CacheSerializer $serializer): void
    {
        $this->serializer = $serializer;
    }

    public function getSerializer(): ?CacheSerializer
    {
        return $this->serializer;
    }

    /**
     * @return array<string>
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->factories);
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->factories[$driver]);
    }

    /**
     * Get supported options for a specific driver.
     */
    public function getSupportedOptions(string $driver): array
    {
        if (!isset($this->factories[$driver])) {
            throw ConfigurationException::missingDriver($driver);
        }

        $factoryClass = $this->factories[$driver];
        /** @var CacheDriverFactory $factory */
        $factory = new $factoryClass();

        return $factory->getSupportedOptions();
    }

    private function registerDefaultFactories(): void
    {
        $defaultFactories = [
            'redis' => RedisDriverFactory::class,
            'memcached' => MemcachedDriverFactory::class,
            'apcu' => ApcuDriverFactory::class,
            'filesystem' => FilesystemDriverFactory::class,
            'array' => ArrayDriverFactory::class,
            'layered' => LayeredDriverFactory::class,
        ];

        foreach ($defaultFactories as $name => $factory) {
            if (class_exists($factory)) {
                $this->factories[$name] = $factory;
            }
        }
    }
}
