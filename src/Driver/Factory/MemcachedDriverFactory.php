<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedAtomicOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedBatchOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedCrudOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedTagOperations;
use KaririCode\Cache\Driver\MemcachedDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\NullLogger;

/**
 * Factory for creating a fully-configured MemcachedDriver with all its dependencies.
 * This class follows the Factory pattern and SRP by encapsulating the entire
 * object creation and composition logic.
 *
 * @see https://www.php.net/manual/en/book.memcached.php Memcached Documentation
 */
final class MemcachedDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'memcached';
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateOptions($options);

        $logger = new NullLogger();

        $config = MemcachedConfig::fromArray($options);

        $memcached = new \Memcached($config->persistentId ?? '');

        // Build Core Components
        $connectionManager = new MemcachedConnectionManager($memcached, $config, $logger);
        $errorHandler = new MemcachedErrorHandler($logger);
        $monitoringService = new MemcachedMonitoringService($memcached, $logger);

        // Build Operation Handlers
        $crudOperations = new MemcachedCrudOperations($memcached, $config, $errorHandler, $connectionManager, $monitoringService);
        $batchOperations = new MemcachedBatchOperations($memcached, $config, $errorHandler, $connectionManager, $monitoringService);
        $atomicOperations = new MemcachedAtomicOperations($memcached, $config, $errorHandler, $connectionManager, $monitoringService);

        $tagOperations = new MemcachedTagOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $crudOperations,
            $batchOperations
        );

        // Build and return the Facade
        return new MemcachedDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $monitoringService,
            $connectionManager
        );
    }

    /**
     * @return list<string>
     */
    public function getSupportedOptions(): array
    {
        return [
            'servers', 'persistent_id', 'username', 'password', 'compression',
            'serializer', 'connect_timeout', 'options', 'sasl_auth',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $this->validateServerOptions($options);
        $this->validateTimeoutOptions($options);
        $this->validateSerializerOption($options);
        $this->validateAuthenticationOptions($options); // Esta chamada agora funciona

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function validateServerOptions(array $options): void
    {
        if (!isset($options['servers']) || !is_array($options['servers'])) {
            return;
        }

        foreach ($options['servers'] as $index => $server) {
            if (!is_array($server) || count($server) < 2) {
                throw ConfigurationException::invalidConfiguration('servers', "Server at index {$index} must be an array with at least [host, port]");
            }
            if (!is_string($server[0]) || empty($server[0])) {
                throw ConfigurationException::invalidConfiguration('servers', "Server host at index {$index} must be a non-empty string");
            }
            if (!is_int($server[1]) || $server[1] < self::MIN_PORT || $server[1] > self::MAX_PORT) {
                throw ConfigurationException::invalidConfiguration('servers', "Server port at index {$index} must be between " . self::MIN_PORT . ' and ' . self::MAX_PORT);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function validateTimeoutOptions(array $options): void
    {
        if (isset($options['connect_timeout']) && (!is_int($options['connect_timeout']) || $options['connect_timeout'] < 1)) {
            throw ConfigurationException::invalidConfiguration('connect_timeout', 'connect_timeout must be a positive integer (milliseconds)');
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function validateSerializerOption(array $options): void
    {
        if (isset($options['serializer'])) {
            $valid = ['php', 'igbinary', 'json'];
            if (!is_string($options['serializer']) || !in_array($options['serializer'], $valid, true)) {
                throw ConfigurationException::invalidConfiguration('serializer', 'Serializer must be one of: ' . implode(', ', $valid));
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function validateAuthenticationOptions(array $options): void
    {
        if (!empty($options['sasl_auth'])) {
            if (empty($options['username']) || !is_string($options['username'])) {
                throw ConfigurationException::invalidConfiguration('username', 'Username is required when SASL authentication is enabled');
            }
            if (empty($options['password']) || !is_string($options['password'])) {
                throw ConfigurationException::invalidConfiguration('password', 'Password is required when SASL authentication is enabled');
            }
        }
    }
}
