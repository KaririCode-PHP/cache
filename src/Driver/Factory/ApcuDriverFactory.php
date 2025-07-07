<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\ApcuDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * APCu driver factory with validation.
 */
final class ApcuDriverFactory implements CacheDriverFactory
{
    public function create(array $config): CacheDriver
    {
        if (!extension_loaded('apcu')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu extension is not loaded');
        }

        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled');
        }

        return new ApcuDriver($config['key_prefix'] ?? 'cache:');
    }

    public function getSupportedOptions(): array
    {
        return [
            'host', 'port', 'password', 'database', 'timeout',
            'persistent', 'compression', 'key_prefix',
        ];
    }

    public function validateOptions(array $options): bool
    {
        // validação das opções
        return true;
    }
}
