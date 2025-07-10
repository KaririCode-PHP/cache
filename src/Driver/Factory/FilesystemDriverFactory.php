<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\FilesystemDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Enhanced filesystem driver factory with comprehensive validation.
 */
final class FilesystemDriverFactory implements CacheDriverFactory
{
    private const DEFAULT_MAX_SIZE = 100 * 1024 * 1024; // 100MB
    private const DEFAULT_CLEANUP_PROBABILITY = 0.01; // 1%
    private const MIN_MAX_SIZE = 1024 * 1024; // 1MB minimum
    private const MAX_DIRECTORY_LEVELS = 4;

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateOptions($options);

        $directory = $this->prepareDirectory($options['directory'] ?? $this->getDefaultDirectory());
        $keyPrefix = $options['key_prefix'] ?? 'cache_';
        $maxSize = $options['max_size'] ?? self::DEFAULT_MAX_SIZE;
        $cleanupProbability = $options['cleanup_probability'] ?? self::DEFAULT_CLEANUP_PROBABILITY;
        $directoryLevels = $options['directory_levels'] ?? 2;
        $useCompression = $options['use_compression'] ?? true;
        $useLocking = $options['use_locking'] ?? true;

        return new FilesystemDriver(
            $directory,
            $keyPrefix,
            $maxSize,
            $cleanupProbability,
            $directoryLevels,
            $useCompression,
            $useLocking
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            'directory', 'key_prefix', 'max_size', 'cleanup_probability',
            'directory_levels', 'use_compression', 'use_locking',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $errors = [];

        // Validate max_size
        if (isset($options['max_size'])) {
            if (!is_int($options['max_size']) || $options['max_size'] < self::MIN_MAX_SIZE) {
                $errors[] = 'max_size must be an integer >= ' . self::MIN_MAX_SIZE;
            }
        }

        // Validate cleanup_probability
        if (isset($options['cleanup_probability'])) {
            if (!is_float($options['cleanup_probability']) && !is_int($options['cleanup_probability'])) {
                $errors[] = 'cleanup_probability must be a number';
            } elseif ($options['cleanup_probability'] < 0 || $options['cleanup_probability'] > 1) {
                $errors[] = 'cleanup_probability must be between 0 and 1';
            }
        }

        // Validate directory_levels
        if (isset($options['directory_levels'])) {
            if (!is_int($options['directory_levels']) || $options['directory_levels'] < 1 || $options['directory_levels'] > self::MAX_DIRECTORY_LEVELS) {
                $errors[] = 'directory_levels must be an integer between 1 and ' . self::MAX_DIRECTORY_LEVELS;
            }
        }

        // Validate boolean options
        $booleanOptions = ['use_compression', 'use_locking'];
        foreach ($booleanOptions as $option) {
            if (isset($options[$option]) && !is_bool($options[$option])) {
                $errors[] = "{$option} must be a boolean";
            }
        }

        // Validate directory if provided
        if (isset($options['directory'])) {
            if (!is_string($options['directory'])) {
                $errors[] = 'directory must be a string';
            }
        }

        if (!empty($errors)) {
            throw ConfigurationException::invalidConfiguration('filesystem', implode(', ', $errors));
        }

        return true;
    }

    private function prepareDirectory(string $directory): string
    {
        $directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;

        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw ConfigurationException::directoryNotWritable($directory);
            }
        }

        // Verify write permissions
        if (!is_writable($directory)) {
            throw ConfigurationException::directoryNotWritable($directory);
        }

        // Check available space
        $freeSpace = disk_free_space($directory);
        if (false === $freeSpace || $freeSpace < self::MIN_MAX_SIZE) {
            throw ConfigurationException::invalidConfiguration('filesystem', "Insufficient disk space in {$directory}");
        }

        return $directory;
    }

    private function getDefaultDirectory(): string
    {
        $candidates = [
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kariricode-cache',
            __DIR__ . '/../../../storage/cache',
            '/tmp/kariricode-cache',
        ];

        foreach ($candidates as $directory) {
            if (is_dir(dirname($directory)) && is_writable(dirname($directory))) {
                return $directory;
            }
        }

        throw ConfigurationException::invalidConfiguration('filesystem', 'No writable directory found for cache storage');
    }
}
