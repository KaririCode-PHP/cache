<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
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
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating a fully-configured ApcuDriver with ExpirationProvider.
 *
 * This factory properly handles the creation and injection of ExpirationProvider
 * into ALL operation classes, including TopicOperations which was previously missing it.
 * Follows Factory pattern and Single Responsibility Principle.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'apcu';

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateRuntime();
        $this->validateOptions($options);

        $logger = $this->resolveLogger($options, $manager);
        $config = ApcuConfig::fromArray($options);

        // Build ExpirationProvider - CRITICAL: Create before all operations
        $expirationProvider = $this->createExpirationProvider($options, $manager);

        // Build Core Components
        $connectionManager = new ApcuConnectionManager($logger);
        $connectionManager->ensureConnection();

        $errorHandler = new ApcuErrorHandler($logger, $config->retryAttempts, $config->retryDelay);
        $monitoringService = new ApcuMonitoringService($config->enableStatistics);
        $maintenanceService = new ApcuMaintenanceService($logger);

        // Build Operation Handlers - CORRECTED: Pass ExpirationProvider to ALL operations
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
            $crudOperations,
            $expirationProvider
        );

        // FIXED: Now passing ExpirationProvider to TopicOperations
        $topicOperations = new ApcuTopicOperations(
            $config,
            $errorHandler,
            $expirationProvider // ✅ CRITICAL FIX: Added missing ExpirationProvider
        );

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
     * @return list<string>
     */
    public function getSupportedOptions(): array
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
            'logger',
            'expiration_provider',
            'default_ttl',
            'operation_ttls',
            'key_pattern_ttls',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $config = ApcuConfig::fromArray($options);
        $config->validate();

        return true;
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    private function validateRuntime(): void
    {
        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled in php.ini (apc.enabled=0)');
        }

        if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled for CLI (apc.enable_cli=0)');
        }
    }

    private function resolveLogger(array $options, DriverManager $manager): LoggerInterface
    {
        if (isset($options['logger']) && $options['logger'] instanceof LoggerInterface) {
            return $options['logger'];
        }

        return new NullLogger();
    }

    /**
     * Creates ExpirationProvider with proper configuration.
     *
     * FIXED: Now properly supports all operation types including topic operations.
     */
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
                'add' => 3600,
                'increment' => 7200,
                'decrement' => 7200,
                'setWithTags' => 1800,
                'subscribeTo' => 3600,
                'compareAndSwap' => 1800,
            ],
            $options['key_pattern_ttls'] ?? [
                'session:*' => 7200,
                'temp:*' => 300,
                'cache:*' => 3600,
                'user:*' => 900,
                'config:*' => 86400,
            ]
        );
    }
}
