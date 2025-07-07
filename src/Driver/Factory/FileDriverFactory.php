<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\FilesystemDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Filesystem driver factory with directory validation.
 */
final class FilesystemDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $directory = $options['directory'] ?? sys_get_temp_dir() . '/kariricode-cache';
        $keyPrefix = $options['key_prefix'] ?? 'cache_';
        $maxSize = $options['max_size'] ?? 100 * 1024 * 1024; // 100MB
        $cleanupProbability = $options['cleanup_probability'] ?? 0.01; // 1%

        // Validate directory
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw ConfigurationException::invalidConfiguration('filesystem', "Cannot create directory: {$directory}");
        }

        if (!is_writable($directory)) {
            throw ConfigurationException::invalidConfiguration('filesystem', "Directory not writable: {$directory}");
        }

        return new FilesystemDriver($directory, $keyPrefix, $maxSize, $cleanupProbability);
    }

    public function getSupportedOptions(): array
    {
        return ['directory', 'key_prefix', 'max_size', 'cleanup_probability'];
    }

    public function validateOptions(array $options): bool
    {
        if (isset($options['max_size']) && (!is_int($options['max_size']) || $options['max_size'] <= 0)) {
            return false;
        }

        if (isset($options['cleanup_probability']) && (!is_float($options['cleanup_probability']) || $options['cleanup_probability'] < 0 || $options['cleanup_probability'] > 1)) {
            return false;
        }

        return true;
    }
}
