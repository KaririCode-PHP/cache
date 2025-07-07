<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriverInterface;
use KaririCode\Cache\Driver\ApcuDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * APCu driver factory with validation.
 */
final class ApcuDriverFactory
{
    public function create(array $config): CacheDriverInterface
    {
        if (!extension_loaded('apcu')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu extension is not loaded');
        }

        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled');
        }

        return new ApcuDriver($config['key_prefix'] ?? 'cache:');
    }
}
