<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Filesystem\Config\FilesystemConfig;
use KaririCode\Cache\Driver\Filesystem\Directory\DirectoryManager;
use KaririCode\Cache\Driver\Filesystem\File\FileManager;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemCrudOperations;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemTagOperations;
use KaririCode\Cache\Driver\Filesystem\Statistics\FileSystemStatistics;
use KaririCode\Cache\Driver\Filesystem\Validation\FileSystemValidator;
use KaririCode\Cache\Driver\FilesystemDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating a fully-configured FilesystemDriver.
 *
 * This factory composes the FilesystemDriver with all its required dependencies,
 * including managers for directories, files, and operations. It follows the
 * Factory and SRP by encapsulating the complex object creation and composition logic.
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
    /**
     * Creates the FilesystemDriver instance.
     *
     * Assembles the driver by creating and injecting all its dependencies, which
     * are all configured via a central FilesystemConfig DTO.
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
        $config = FilesystemConfig::fromConfig($options);

        // Resolve the global serializer instead of using a local one.
        $serializer = $this->resolveSerializer($options, $manager);
        if (null === $serializer) {
            throw ConfigurationException::missingConfiguration('A CacheSerializer must be configured in the DriverManager.');
        }

        // Build Core Components, all configured via the DTO
        $directoryManager = new DirectoryManager($config, $logger);
        $fileManager = new FileManager($config, $logger);
        $validator = new FileSystemValidator($config);
        $statistics = new FileSystemStatistics($directoryManager, $fileManager, $logger);

        // Build Operation Handlers
        $crudOperations = new FilesystemCrudOperations(
            $config,
            $directoryManager,
            $fileManager,
            $serializer,
            $expirationProvider,
            $logger
        );

        $tagOperations = new FilesystemTagOperations(
            $config,
            $directoryManager,
            $fileManager,
            $serializer,
            $expirationProvider,
            $logger,
            $crudOperations // Inject CRUD ops for get/delete functionality
        );

        // Assemble the final driver Facade
        return new FilesystemDriver(
            $directoryManager,
            $crudOperations,
            $tagOperations,
            $statistics,
            $validator,
            $logger
        );
    }

    protected function getDriverName(): string
    {
        return 'filesystem';
    }

    protected function getRequiredOptions(): array
    {
        // A directory is required, but the DTO provides a sensible default.
        return [];
    }

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
            'max_key_length',
            'max_value_size',
        ];
    }

    protected function doValidateOptions(array $options): bool
    {
        // Validation is handled by the FilesystemConfig DTO upon creation.
        // If this doesn't throw an exception, the options are valid.
        FilesystemConfig::fromConfig($options);

        return true;
    }
}
