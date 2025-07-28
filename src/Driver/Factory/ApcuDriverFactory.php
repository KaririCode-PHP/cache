<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

        // Build Core Components
        $connectionManager = new ApcuConnectionManager($logger);
        $connectionManager->ensureConnection();

        $errorHandler = new ApcuErrorHandler($logger, $config->retryAttempts, $config->retryDelay);

        // CORREÇÃO: Instanciar os dois serviços separadamente
        $monitoringService = new ApcuMonitoringService($config->enableStatistics);
        $maintenanceService = new ApcuMaintenanceService($logger);

        // Build Operation Handlers
        $crudOperations = new ApcuCrudOperations($config, $errorHandler, $monitoringService);
        $batchOperations = new ApcuBatchOperations($config, $errorHandler, $monitoringService);
        $atomicOperations = new ApcuAtomicOperations($config, $errorHandler, $monitoringService);
        $tagOperations = new ApcuTagOperations($config, $errorHandler, $crudOperations);
        $topicOperations = new ApcuTopicOperations($config, $errorHandler);

        // CORREÇÃO: Passar o novo serviço de manutenção para o driver
        return new ApcuDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $topicOperations,
            $monitoringService,
            $maintenanceService // Adicionado
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
        ];
    }

    public function validateOptions(array $options): bool
    {
        $config = ApcuConfig::fromArray($options);
        $config->validate(); // The DTO now handles its own validation.

        return true;
    }

    /**
     * Validates that the APCu extension is loaded.
     */
    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    /**
     * Validates runtime APCu configuration.
     */
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
}
