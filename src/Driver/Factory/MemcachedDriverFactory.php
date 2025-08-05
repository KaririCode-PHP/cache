<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
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
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating a fully-configured MemcachedDriver with ExpirationProvider.
 * Follows Factory pattern and SRP - handles complete object creation and composition.
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

        $logger = $this->resolveLogger($options, $manager);
        $config = MemcachedConfig::fromArray($options);

        // Build ExpirationProvider - CRITICAL: Create before all operations
        $expirationProvider = $this->createExpirationProvider($options, $manager);

        // Build Core Components
        $memcached = $this->createMemcachedInstance($config);
        $connectionManager = new MemcachedConnectionManager($memcached, $config, $logger);
        $connectionManager->ensureConnection();

        $errorHandler = new MemcachedErrorHandler($logger, $config->retryAttempts, $config->retryDelay);
        $monitoringService = new MemcachedMonitoringService($memcached, $logger);

        // Build Operation Handlers - CORRECTED: Pass ExpirationProvider to ALL operations
        $crudOperations = new MemcachedCrudOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $batchOperations = new MemcachedBatchOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $atomicOperations = new MemcachedAtomicOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $tagOperations = new MemcachedTagOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        return new MemcachedDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $monitoringService,
            $connectionManager,
            $expirationProvider
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            'servers', 'persistent_id', 'username', 'password',
            'compression', 'serializer', 'binary_protocol',
            'consistent_hashing', 'connect_timeout', 'poll_timeout',
            'recv_timeout', 'send_timeout', 'tcp_nodelay',
            'retry_attempts', 'retry_delay', 'key_prefix',
            'expiration_provider', 'default_ttl', 'operation_ttls',
            'key_pattern_ttls', 'logger',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $this->validateServerOptions($options);
        $this->validatePortOptions($options);
        $this->validateTimeoutOptions($options);
        $this->validateSerializerOption($options);
        $this->validateAuthenticationOptions($options);

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    private function createMemcachedInstance(MemcachedConfig $config): \Memcached
    {
        $memcached = new \Memcached($config->persistentId ?? '');

        // Configure Memcached options
        $memcached->setOption(\Memcached::OPT_COMPRESSION, $config->compression);
        $memcached->setOption(\Memcached::OPT_SERIALIZER, $config->getSerializerOption());
        $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, $config->binaryProtocol);
        $memcached->setOption(\Memcached::OPT_DISTRIBUTION, \Memcached::DISTRIBUTION_CONSISTENT);
        $memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);

        if ($config->tcpNoDelay) {
            $memcached->setOption(\Memcached::OPT_TCP_NODELAY, true);
        }

        // Configure timeouts
        $memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, $config->connectTimeout);
        $memcached->setOption(\Memcached::OPT_POLL_TIMEOUT, $config->pollTimeout);
        $memcached->setOption(\Memcached::OPT_RECV_TIMEOUT, $config->recvTimeout);
        $memcached->setOption(\Memcached::OPT_SEND_TIMEOUT, $config->sendTimeout);

        return $memcached;
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
                'add' => 3600,
                'increment' => 7200,
                'decrement' => 7200,
                'setWithTags' => 1800,
                'compareAndSwap' => 1800,
            ],
            $options['key_pattern_ttls'] ?? [
                'session:*' => 7200,
                'api:*' => 1800,
                'temp:*' => 300,
                'user:*' => 900,
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

    private function validateServerOptions(array $options): void
    {
        if (isset($options['servers']) && !is_array($options['servers'])) {
            throw ConfigurationException::invalidConfiguration('servers', 'Servers must be an array');
        }
    }

    private function validatePortOptions(array $options): void
    {
        if (isset($options['servers']) && is_array($options['servers'])) {
            foreach ($options['servers'] as $server) {
                if (isset($server['port'])) {
                    $port = $server['port'];
                    if (!is_int($port) || $port < self::MIN_PORT || $port > self::MAX_PORT) {
                        throw ConfigurationException::invalidConfiguration('port', 'Port must be between ' . self::MIN_PORT . ' and ' . self::MAX_PORT);
                    }
                }
            }
        }
    }

    private function validateTimeoutOptions(array $options): void
    {
        $timeoutOptions = ['connect_timeout', 'poll_timeout', 'recv_timeout', 'send_timeout'];
        foreach ($timeoutOptions as $option) {
            if (isset($options[$option])) {
                $value = $options[$option];
                if (!is_int($value) || $value < 1) {
                    throw ConfigurationException::invalidConfiguration($option, ucfirst($option) . ' must be a positive integer (milliseconds)');
                }
            }
        }
    }

    private function validateSerializerOption(array $options): void
    {
        if (isset($options['serializer'])) {
            $valid = ['php', 'igbinary', 'json'];
            if (!is_string($options['serializer']) || !in_array($options['serializer'], $valid, true)) {
                throw ConfigurationException::invalidConfiguration('serializer', 'Serializer must be one of: ' . implode(', ', $valid));
            }
        }
    }

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
