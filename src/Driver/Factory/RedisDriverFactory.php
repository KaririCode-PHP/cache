<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
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
use KaririCode\Cache\Serializer\IgbinarySerializer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating a fully-configured RedisDriver with all its dependencies.
 * This class follows the Factory pattern and SRP by encapsulating the entire
 * object creation and composition logic.
 *
 * @see https://redis.io/documentation Redis Documentation
 * @see https://github.com/phpredis/phpredis PHPRedis Extension
 */
final class RedisDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'redis';
    private const DEFAULT_TIMEOUT = 2.0;
    private const DEFAULT_READ_TIMEOUT = 2.0;
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;
    private const CONNECTION_RETRY_ATTEMPTS = 3;
    private const CONNECTION_RETRY_DELAY = 0.1;

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateOptions($options);

        $redis = new \Redis();
        $logger = $this->resolveLogger($options, $manager);

        $config = RedisConfig::fromArray($options + $this->getDefaultOptions());
        $config->validate();

        $errorHandler = new RedisErrorHandler($logger, $config);
        $connectionManager = new RedisConnectionManager($redis, $config, $logger);
        $luaScriptManager = new RedisLuaScriptManager($redis);

        // Pass the manager to the configuration method to inspect the serializer.
        $this->configureRedisInstance($redis, $config, $connectionManager, $manager);

        $crudOperations = new RedisCrudOperations($redis, $config, $errorHandler, $connectionManager);
        $batchOperations = new RedisBatchOperations($redis, $config, $errorHandler, $connectionManager);
        $atomicOperations = new RedisAtomicOperations($redis, $config, $errorHandler, $connectionManager, $luaScriptManager);
        $tagOperations = new RedisTagOperations($redis, $config, $errorHandler, $connectionManager);
        $topicOperations = new RedisTopicOperations($redis, $config, $errorHandler, $connectionManager);
        $monitoringService = new RedisMonitoringService($redis, $logger);

        return new RedisDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $topicOperations,
            $monitoringService,
            $connectionManager
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
            'logger', 'connection', 'ssl_context',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $this->validateRequiredOptions($options);
        $this->validateConnectionOptions($options);
        $this->validateOptionalOptions($options);
        $this->validateSecurityOptions($options);

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
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
     * @return array<string, mixed>
     */
    private function getDefaultOptions(): array
    {
        return [
            'host' => 'localhost',
            'port' => 6379,
            'timeout' => self::DEFAULT_TIMEOUT,
            'read_timeout' => self::DEFAULT_READ_TIMEOUT,
            'retry_attempts' => self::CONNECTION_RETRY_ATTEMPTS,
            'retry_delay' => self::CONNECTION_RETRY_DELAY,
            'persistent' => false,
            'database' => 0,
            'compression' => false,
            'serializer' => 'php',
            'scan_batch_size' => 500,
            'pipeline_ops' => true,
        ];
    }

    private function validateRequiredOptions(array $options): void
    {
        if (!isset($options['host']) || !is_string($options['host']) || '' === trim($options['host'])) {
            throw ConfigurationException::invalidConfiguration('host', 'Required option is missing or empty');
        }
    }

    private function validateConnectionOptions(array $options): void
    {
        if (isset($options['port'])) {
            $port = (int) $options['port'];
            if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
                throw ConfigurationException::invalidPort($port, self::MIN_PORT, self::MAX_PORT);
            }
        }

        $timeoutOptions = ['timeout', 'read_timeout'];
        foreach ($timeoutOptions as $option) {
            if (isset($options[$option]) && is_numeric($options[$option])) {
                $timeout = (float) $options[$option];
                if ($timeout <= 0) {
                    throw ConfigurationException::invalidConfiguration($option, 'Timeout must be a positive number');
                }
            }
        }

        if (!empty($options['persistent']) && empty($options['persistent_id'])) {
            throw ConfigurationException::invalidConfiguration('persistent_id', 'Persistent ID is required for persistent connections');
        }
    }

    private function validateOptionalOptions(array $options): void
    {
        if (isset($options['database'])) {
            $database = (int) $options['database'];
            if ($database < 0 || $database > 15) {
                throw ConfigurationException::invalidConfiguration('database', 'Database must be between 0 and 15');
            }
        }

        if (isset($options['serializer']) && is_string($options['serializer'])) {
            if (!in_array($options['serializer'], ['php', 'igbinary', 'json'], true)) {
                throw ConfigurationException::invalidConfiguration('serializer', 'Serializer must be one of: php, igbinary, json');
            }
        }
    }

    private function validateSecurityOptions(array $options): void
    {
        if (isset($options['password']) && !is_string($options['password'])) {
            throw ConfigurationException::invalidConfiguration('password', 'Password must be a string');
        }

        if (isset($options['ssl_context']) && !is_array($options['ssl_context'])) {
            throw ConfigurationException::invalidConfiguration('ssl_context', 'SSL context must be an array');
        }
    }

    private function configureRedisInstance(
        \Redis $redis,
        RedisConfig $config,
        RedisConnectionManager $connectionManager,
        DriverManager $manager
    ): void {
        try {
            $phpSerializer = $manager->getSerializer();
            $serializerOption = \Redis::SERIALIZER_PHP; // Default

            if ($phpSerializer instanceof IgbinarySerializer) {
                $serializerOption = \Redis::SERIALIZER_IGBINARY;
            }

            $redis->setOption(\Redis::OPT_SERIALIZER, $serializerOption);

            $connectionManager->ensureConnection();

            if ($config->readTimeout > 0) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, $config->readTimeout);
            }
        } catch (\RedisException $e) {
            throw ConfigurationException::invalidConfiguration('redis_configuration', 'Failed to configure Redis instance: ' . $e->getMessage());
        }
    }
}
