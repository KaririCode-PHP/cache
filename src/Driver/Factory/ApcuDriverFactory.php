<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuConnectionManager;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMaintenanceService;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuAtomicOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuBatchOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuCrudOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTagOperations;
use KaririCode\Cache\Driver\Apcu\Operation\ApcuTopicOperations;
use KaririCode\Cache\Driver\ApcuDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\Logging\Logger;

/**
 * Factory for creating a fully-configured ApcuDriver.
 *
 * This factory composes the ApcuDriver with all its required dependencies,
 * including various operation handlers (CRUD, atomic, batch, etc.) and
 * core components for connection, error handling, and monitoring.
 *
 * It follows the Factory and Single Responsibility Principle by encapsulating
 * the complex object creation logic. It expects a pre-configured ExpirationProvider
 * to be passed in the options, ensuring a single source of truth for TTL management.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuDriverFactory extends AbstractDriverFactory
{
    private const REQUIRED_EXTENSION = 'apcu';

    /**
     * Creates the actual driver instance.
     *
     * This method orchestrates the assembly of the ApcuDriver by creating and
     * injecting all its operational and infrastructural dependencies.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for resolving shared dependencies
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param Logger $logger the logger instance
     *
     * @return CacheDriver the fully configured ApcuDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        Logger $logger
    ): CacheDriver {
        $config = ApcuConfig::fromArray($options);

        // Build Core Components
        $connectionManager = new ApcuConnectionManager($logger);
        $connectionManager->ensureConnection();

        $errorHandler = new ApcuErrorHandler($logger, $config->retryAttempts, $config->retryDelay);
        $monitoringService = new ApcuMonitoringService($config->enableStatistics);
        $maintenanceService = new ApcuMaintenanceService($logger);

        // Build Operation Handlers, injecting all necessary dependencies
        $crudOperations = new ApcuCrudOperations(
            $config,
            $errorHandler,
            $monitoringService,
            $expirationProvider
        );

        $batchOperations = new ApcuBatchOperations(
            $config,
            $errorHandler,
            $monitoringService,
            $expirationProvider
        );

        $atomicOperations = new ApcuAtomicOperations(
            $config,
            $errorHandler,
            $monitoringService,
            $expirationProvider
        );

        $tagOperations = new ApcuTagOperations(
            $config,
            $errorHandler,
            $crudOperations, // Tag operations may depend on CRUD for some actions
            $expirationProvider
        );

        $topicOperations = new ApcuTopicOperations(
            $config,
            $errorHandler
            // Note: TopicOperations for APCu might not need expiration provider
            // if it only manages subscriptions without TTL. Pass it if needed.
        );

        // Assemble the final driver
        return new ApcuDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $topicOperations,
            $monitoringService,
            $maintenanceService,
            $expirationProvider
        );
    }

    /**
     * Returns the unique name of the driver.
     *
     * @return string the driver's name
     */
    protected function getDriverName(): string
    {
        return 'apcu';
    }

    /**
     * Specifies the configuration options required for this driver.
     *
     * @return array<string> a list of required option keys
     */
    protected function getRequiredOptions(): array
    {
        // APCu can often run with sensible defaults, so no options are strictly required.
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
            'key_prefix',
            'tag_prefix',
            'topic_prefix',
            'lock_timeout',
            'enable_statistics',
            'cleanup_probability',
            'retry_attempts',
            'retry_delay',
        ];
    }

    /**
     * Performs driver-specific validation of the provided options.
     *
     * @param array<string, mixed> $options the options to validate
     *
     * @return bool true if the options are valid
     */
    protected function doValidateOptions(array $options): bool
    {
        // The ApcuConfig DTO handles its own validation upon creation.
        // This ensures that if the config object can be created, the options are valid.
        ApcuConfig::fromArray($options);

        return true;
    }

    /**
     * Validates the runtime environment prerequisites for the APCu driver.
     *
     * @throws ConfigurationException if a required extension or setting is missing
     */
    protected function validatePrerequisites(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }

        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled in php.ini (apc.enabled=0)');
        }

        if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled for CLI (apc.enable_cli=0)');
        }
    }
}
