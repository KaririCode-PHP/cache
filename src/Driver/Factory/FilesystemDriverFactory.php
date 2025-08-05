<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Factory\Adapter\FilesystemExpirationAdapter;
use KaririCode\Cache\Driver\Filesystem\Directory\DirectoryManager;
use KaririCode\Cache\Driver\Filesystem\File\FileManager;
use KaririCode\Cache\Driver\Filesystem\Operation\FileSystemCacheOperations;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemTagOperations;
use KaririCode\Cache\Driver\Filesystem\Serialization\SerializationManager;
use KaririCode\Cache\Driver\Filesystem\Statistics\FileSystemStatistics;
use KaririCode\Cache\Driver\Filesystem\Validation\FileSystemValidator;
use KaririCode\Cache\Driver\FilesystemDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating a fully-configured FilesystemDriver with ExpirationProvider.
 * Follows Factory pattern and SRP - handles complete object creation and composition.
 */
final class FilesystemDriverFactory implements CacheDriverFactory
{
    private const MIN_MAX_SIZE = 1024 * 1024; // 1MB minimum
    private const MAX_DIRECTORY_LEVELS = 4;

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateOptions($options);

        $logger = $this->resolveLogger($options, $manager);
        $keyPrefix = $options['key_prefix'] ?? 'cache_';

        // Build ExpirationProvider - CRITICAL: Create before all operations
        $expirationProvider = $this->createExpirationProvider($options, $manager);

        // Build Core Components
        $fileManager = new FileManager($logger, $options['use_locking'] ?? true);
        $directoryManager = new DirectoryManager(
            $options['directory'] ?? $this->getDefaultDirectory(),
            $logger
        );

        $serializer = new SerializationManager(
            $logger,
            $options['use_compression'] ?? false
        );

        // Use FilesystemExpirationAdapter to bridge ExpirationProvider to ExpirationManager
        $expirationManager = new FilesystemExpirationAdapter(
            $expirationProvider,
            $options['cleanup_probability'] ?? 0.01
        );

        $statistics = new FileSystemStatistics(
            $directoryManager,
            $fileManager,
            $logger
        );

        $validator = new FileSystemValidator(
            $directoryManager,
            $options['max_size'] ?? 104857600
        );

        $cacheOperations = new FileSystemCacheOperations(
            $directoryManager,
            $fileManager,
            $serializer,
            $expirationManager,
            $logger,
            $keyPrefix
        );

        $tagOperations = new FilesystemTagOperations(
            $fileManager,
            $directoryManager,
            $serializer,
            $logger,
            $expirationProvider,
            $keyPrefix
        );

        return new FilesystemDriver(
            $directoryManager,
            $cacheOperations,
            $tagOperations,
            $statistics,
            $validator,
            $expirationProvider,
            $logger
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            'directory',
            'key_prefix',
            'max_size',
            'cleanup_probability',
            'use_locking',
            'use_compression',
            'directory_levels',
            'file_permissions',
            'directory_permissions',
            'expiration_provider',
            'default_ttl',
            'operation_ttls',
            'key_pattern_ttls',
            'logger',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $this->validateDirectoryOption($options);
        $this->validateMaxSizeOption($options);
        $this->validateDirectoryLevelsOption($options);
        $this->validatePermissionsOptions($options);

        return true;
    }

    private function createExpirationProvider(array $options, DriverManager $manager): ExpirationProvider
    {
        if (isset($options['expiration_provider']) && $options['expiration_provider'] instanceof ExpirationProvider) {
            return $options['expiration_provider'];
        }

        return new ConfigurableExpirationProvider(
            $options['default_ttl'] ?? 3600,
            $options['operation_ttls'] ?? [
                'set' => 3600,
                'setMultiple' => 3600,
                'setWithTags' => 1800,
            ],
            $options['key_pattern_ttls'] ?? [
                'session:*' => 7200,
                'temp:*' => 300,
                'cache:*' => 3600,
            ]
        );
    }

    private function resolveLogger(array $options, DriverManager $manager): LoggerInterface
    {
        if (isset($options['logger']) && $options['logger'] instanceof LoggerInterface) {
            return $options['logger'];
        }

        return new NullLogger();
    }

    private function getDefaultDirectory(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kariricode_cache';
    }

    private function validateDirectoryOption(array $options): void
    {
        if (isset($options['directory'])) {
            $directory = $options['directory'];
            if (!is_string($directory) || empty($directory)) {
                throw ConfigurationException::invalidConfiguration('directory', 'Directory must be a non-empty string');
            }

            // Create directory if it doesn't exist
            if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
                throw ConfigurationException::invalidConfiguration('directory', "Cannot create directory: {$directory}");
            }

            // Check if directory is writable
            if (!is_writable($directory)) {
                throw ConfigurationException::invalidConfiguration('directory', "Directory is not writable: {$directory}");
            }
        }
    }

    private function validateMaxSizeOption(array $options): void
    {
        if (isset($options['max_size'])) {
            $maxSize = $options['max_size'];
            if (!is_int($maxSize) || $maxSize < self::MIN_MAX_SIZE) {
                throw ConfigurationException::invalidConfiguration('max_size', 'Max size must be at least ' . self::MIN_MAX_SIZE . ' bytes (1MB)');
            }
        }
    }

    private function validateDirectoryLevelsOption(array $options): void
    {
        if (isset($options['directory_levels'])) {
            $levels = $options['directory_levels'];
            if (!is_int($levels) || $levels < 0 || $levels > self::MAX_DIRECTORY_LEVELS) {
                throw ConfigurationException::invalidConfiguration('directory_levels', 'Directory levels must be between 0 and ' . self::MAX_DIRECTORY_LEVELS);
            }
        }
    }

    private function validatePermissionsOptions(array $options): void
    {
        if (isset($options['file_permissions'])) {
            $perms = $options['file_permissions'];
            if (!is_int($perms) || $perms < 0 || $perms > 0777) {
                throw ConfigurationException::invalidConfiguration('file_permissions', 'File permissions must be a valid octal value (0-0777)');
            }
        }

        if (isset($options['directory_permissions'])) {
            $perms = $options['directory_permissions'];
            if (!is_int($perms) || $perms < 0 || $perms > 0777) {
                throw ConfigurationException::invalidConfiguration('directory_permissions', 'Directory permissions must be a valid octal value (0-0777)');
            }
        }
    }
}
