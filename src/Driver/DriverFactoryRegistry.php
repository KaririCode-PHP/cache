<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Exception\ConfigurationException;

final class DriverFactoryRegistry
{
    /** @var array<string, class-string<CacheDriverFactory>> */
    private array $factories = [];

    public function register(string $name, string $factoryClass): void
    {
        if (!is_subclass_of($factoryClass, CacheDriverFactory::class)) {
            throw ConfigurationException::invalidFactory($name, $factoryClass, CacheDriverFactory::class);
        }
        $this->factories[$name] = $factoryClass;
    }
}
