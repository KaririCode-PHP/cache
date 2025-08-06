<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
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
use Psr\Log\LoggerInterface;

/**
 * Factory for creating a fully-configured FilesystemDriver.
 *
 * This factory composes the FilesystemDriver with all its required dependencies,
 * including managers for directories, files, serialization, and operations.
 * It follows the Factory pattern and SRP by encapsulating the complex object
 * creation and composition logic.
 *
 * It expects a pre-configured ExpirationProvider to be passed in, which is then
 * adapted for use by the filesystem-specific components.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FilesystemDriverFactory extends AbstractDriverFactory
{
    private const MIN_MAX_SIZE = 1024 * 1024; // 1MB minimum
    private const MAX_DIRECTORY_LEVELS = 4;

    /**
     * Creates the FilesystemDriver instance.
     *
     * Assembles the driver by creating and injecting all its dependencies, including
     * an adapter to bridge the generic ExpirationProvider with the filesystem's
     * specific ExpirationManager contract.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for resolving shared dependencies
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param LoggerInterface $logger the logger instance
     *
     * @return CacheDriver the fully configured FilesystemDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        LoggerInterface $logger
    ): CacheDriver {
        $keyPrefix = $options['key_prefix'] ?? 'cache_';

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

        // ADAPTER PATTERN: Bridge the generic ExpirationProvider to the specific ExpirationManager interface.
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
            $options['max_size'] ?? (100 * 1024 * 1024) // 100MB
        );

        // Build Operation Handlers
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
            $expirationProvider, // Tag operations can use the provider directly
            $keyPrefix
        );

        // Assemble the final driver
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

    /**
     * Returns the unique name of the driver.
     *
     * @return string the driver's name
     */
    protected function getDriverName(): string
    {
        return 'filesystem';
    }

    /**
     * Specifies the configuration options required for this driver.
     *
     * @return array<string> a list of required option keys
     */
    protected function getRequiredOptions(): array
    {
        // A directory is required, but we can provide a sensible default.
        return [];
    }

    /**
     * Specifies the optional configuration options supported by this driver.
     *
     * @return array<string> a list of optional option keys
     */
    protected function getOptionalOptions(): array
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
        ];
    }

    /**
     * Performs driver-specific validation of the provided options.
     *
     * @param array<string, mixed> $options the options to validate
     *
     * @throws ConfigurationException if any option is invalid
     *
     * @return bool true if the options are valid
     */
    protected function doValidateOptions(array $options): bool
    {
        $this->validateDirectoryOption($options);
        $this->validateMaxSizeOption($options);
        $this->validateDirectoryLevelsOption($options);
        $this->validatePermissionsOptions($options);

        return true;
    }

    /**
     * Gets a default, safe directory for caching within the system's temp folder.
     *
     * @return string the default cache directory path
     */
    private function getDefaultDirectory(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kariricode_cache';
    }

    /**
     * Validates the 'directory' option, ensuring it's a writable path.
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    private function validateDirectoryOption(array $options): void
    {
        $directory = $options['directory'] ?? $this->getDefaultDirectory();

        if (!is_string($directory) || empty($directory)) {
            throw ConfigurationException::invalidConfiguration('directory', 'Directory must be a non-empty string');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw ConfigurationException::directoryNotWritable(sprintf('Cache directory "%s" could not be created.', $directory));
        }

        if (!is_writable($directory)) {
            throw ConfigurationException::directoryNotWritable(sprintf('Cache directory "%s" is not writable.', $directory));
        }
    }

    /**
     * Validates the 'max_size' option.
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    private function validateMaxSizeOption(array $options): void
    {
        if (isset($options['max_size'])) {
            $maxSize = $options['max_size'];
            if (!is_int($maxSize) || $maxSize < self::MIN_MAX_SIZE) {
                throw ConfigurationException::invalidOption('max_size', $maxSize, 'an integer greater than ' . self::MIN_MAX_SIZE);
            }
        }
    }

    /**
     * Validates the 'directory_levels' option.
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    private function validateDirectoryLevelsOption(array $options): void
    {
        if (isset($options['directory_levels'])) {
            $levels = $options['directory_levels'];
            if (!is_int($levels) || $levels < 0 || $levels > self::MAX_DIRECTORY_LEVELS) {
                throw ConfigurationException::invalidOption('directory_levels', $levels, 'an integer between 0 and ' . self::MAX_DIRECTORY_LEVELS);
            }
        }
    }

    /**
     * Validates file and directory permission options.
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    private function validatePermissionsOptions(array $options): void
    {
        if (isset($options['file_permissions'])) {
            $perms = $options['file_permissions'];
            if (!is_int($perms) || $perms < 0 || $perms > 0777) {
                throw ConfigurationException::invalidOption('file_permissions', $perms, 'a valid octal value (e.g., 0644)');
            }
        }

        if (isset($options['directory_permissions'])) {
            $perms = $options['directory_permissions'];
            if (!is_int($perms) || $perms < 0 || $perms > 0777) {
                throw ConfigurationException::invalidOption('directory_permissions', $perms, 'a valid octal value (e.g., 0755)');
            }
        }
    }
}
