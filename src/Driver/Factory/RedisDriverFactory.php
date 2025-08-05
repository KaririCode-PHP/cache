<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\Redis\Operation\RedisAtomicOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisBatchOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisCrudOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTagOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTopicOperations;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use KaririCode\Cache\Serializer\IgbinarySerializer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating a fully-configured RedisDriver with ExpirationProvider.
 * Follows Factory pattern and SRP - handles complete object creation and composition.
 */
final class RedisDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'redis';
    private const DEFAULT_TIMEOUT = 2.0;
    private const DEFAULT_READ_TIMEOUT = 2.0;
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateOptions($options);

        $redis = new \Redis();
        $logger = $this->resolveLogger($options, $manager);

        $config = RedisConfig::fromArray($options + $this->getDefaultOptions());
        $config->validate();

        // Build ExpirationProvider - CRITICAL: Create before all operations
        $expirationProvider = $this->createExpirationProvider($options, $manager);

        // Build Core Components
        $errorHandler = new RedisErrorHandler($logger, $config);
        $connectionManager = new RedisConnectionManager($redis, $config, $logger);
        $luaScriptManager = new RedisLuaScriptManager($redis);

        // Configure Redis instance
        $this->configureRedisInstance($redis, $config, $connectionManager, $manager);

        // Build Operation Handlers - CORRECTED: Pass ExpirationProvider to ALL operations
        $crudOperations = new RedisCrudOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            new RedisMonitoringService($redis, $logger),
            $expirationProvider
        );

        $batchOperations = new RedisBatchOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $expirationProvider
        );

        $atomicOperations = new RedisAtomicOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $luaScriptManager,
            $expirationProvider
        );

        $tagOperations = new RedisTagOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $expirationProvider
        );

        $topicOperations = new RedisTopicOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager
        );

        $monitoringService = new RedisMonitoringService($redis, $logger);

        return new RedisDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $topicOperations,
            $monitoringService,
            $connectionManager,
            $expirationProvider
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            'host', 'port', 'password', 'username', 'database',
            'timeout', 'read_timeout', 'persistent', 'persistent_id',
            'compression', 'serializer', 'retry_attempts', 'retry_delay',
            'scan_batch_size', 'pipeline_ops', 'key_prefix', 'tag_prefix',
            'topic_prefix', 'cluster_mode', 'sentinel_mode', 'max_connections',
            'logger', 'connection', 'ssl_context', 'expiration_provider',
            'default_ttl', 'operation_ttls', 'key_pattern_ttls',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $this->validateRequiredOptions($options);
        $this->validatePortOptions($options);
        $this->validateTimeoutOptions($options);

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    private function getDefaultOptions(): array
    {
        return [
            'host' => 'localhost',
            'port' => 6379,
            'timeout' => self::DEFAULT_TIMEOUT,
            'read_timeout' => self::DEFAULT_READ_TIMEOUT,
            'persistent' => false,
            'compression' => false,
            'serializer' => 'php',
            'retry_attempts' => 3,
            'retry_delay' => 0.1,
            'scan_batch_size' => 500,
            'key_prefix' => '',
            'tag_prefix' => 'tag:',
            'topic_prefix' => 'topic:',
        ];
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

    private function configureRedisInstance(
        \Redis $redis,
        RedisConfig $config,
        RedisConnectionManager $connectionManager,
        DriverManager $manager
    ): void {
        $connectionManager->ensureConnection();

        // Configure serializer
        if ($config->compression) {
            $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZ4);
        }

        // Configure serializer based on config
        $serializer = $manager->getSerializer();
        if ($serializer instanceof IgbinarySerializer && extension_loaded('igbinary')) {
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
        } else {
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        }
    }

    private function validateRequiredOptions(array $options): void
    {
        // Add any required option validations here
    }

    private function validatePortOptions(array $options): void
    {
        if (isset($options['port'])) {
            $port = $options['port'];
            if (!is_int($port) || $port < self::MIN_PORT || $port > self::MAX_PORT) {
                throw ConfigurationException::invalidConfiguration('port', 'Port must be an integer between ' . self::MIN_PORT . ' and ' . self::MAX_PORT);
            }
        }
    }

    private function validateTimeoutOptions(array $options): void
    {
        foreach (['timeout', 'read_timeout'] as $option) {
            if (isset($options[$option])) {
                $value = $options[$option];
                if (!is_numeric($value) || $value <= 0) {
                    throw ConfigurationException::invalidConfiguration($option, ucfirst($option) . ' must be a positive number');
                }
            }
        }
    }
}
