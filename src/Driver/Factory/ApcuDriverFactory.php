<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\ApcuDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Enhanced APCu driver factory with validation.
 */
final class ApcuDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'apcu';

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateRuntime();

        return new ApcuDriver(
            $options['key_prefix'] ?? 'cache:'
        );
    }

    public function getSupportedOptions(): array
    {
        return ['key_prefix'];
    }

    public function validateOptions(array $options): bool
    {
        if (isset($options['key_prefix']) && !is_string($options['key_prefix'])) {
            throw ConfigurationException::invalidConfiguration('apcu', 'key_prefix must be a string');
        }

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    private function validateRuntime(): void
    {
        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled in php.ini');
        }

        // Check if CLI and web environments are properly configured
        if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled for CLI (apc.enable_cli=0)');
        }
    }
}
