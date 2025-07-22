<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced Redis driver factory following SOLID principles.
 *
 * This factory creates a fully configured RedisDriver with all its composed components,
 * ensuring proper dependency injection and configuration validation.
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

    /**
     * Creates a fully configured RedisDriver with all composed components.
     *
     * @param array<string, mixed> $options Configuration options
     * @param DriverManager $manager Driver manager for shared dependencies
     *
     * @throws ConfigurationException When configuration validation fails
     *
     * @return CacheDriver Configured Redis cache driver
     */
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateOptions($options);

        // Create core Redis instance
        $redis = $this->createRedisInstance($options);

        // Create configuration and logger
        $config = $this->createRedisConfig($options);
        $logger = $this->resolveLogger($options, $manager);

        // Validate configuration consistency
        $config->validate();

        // Create core components following composition pattern
        $errorHandler = new RedisErrorHandler($logger, $config);
        $connectionManager = new RedisConnectionManager($redis, $config, $errorHandler, $logger);
        $luaScriptManager = new RedisLuaScriptManager($redis, $logger);

        // Configure Redis connection and options
        $this->configureRedisInstance($redis, $options);
        $this->performHealthCheck($connectionManager);

        // Create the main driver with all composed components
        return new RedisDriver(
            $redis,
            $options['key_prefix'] ?? 'cache:',
            $options['tag_prefix'] ?? 'tag:',
            $options['topic_prefix'] ?? 'topic:',
            $logger,
            $options['connection'] ?? []
        );
    }

    /**
     * Validates driver configuration options.
     *
     * @param array<string, mixed> $options Configuration options to validate
     *
     * @throws ConfigurationException When validation fails
     *
     * @return bool Always true if validation passes
     */
    public function validateOptions(array $options): bool
    {
        $this->validateRequiredOptions($options);
        $this->validateConnectionOptions($options);
        $this->validateOptionalOptions($options);
        $this->validateSecurityOptions($options);

        return true;
    }

    /**
     * Returns list of supported configuration options.
     *
     * @return array<int, string> Supported option names
     */
    public function getSupportedOptions(): array
    {
        return [
            // Connection options
            'host', 'port', 'password', 'username', 'database',
            'timeout', 'read_timeout', 'persistent', 'persistent_id',

            // Performance options
            'compression', 'serializer', 'retry_attempts', 'retry_delay',
            'scan_batch_size', 'pipeline_ops',

            // Prefix options
            'key_prefix', 'tag_prefix', 'topic_prefix',

            // Advanced options
            'cluster_mode', 'sentinel_mode', 'max_connections',
            'logger', 'connection', 'ssl_context',
        ];
    }

    /**
     * Validates that Redis extension is available.
     *
     * @throws ConfigurationException When Redis extension is not loaded
     */
    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    /**
     * Creates and initializes Redis instance.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return \Redis Configured Redis instance
     */
    private function createRedisInstance(array $options): \Redis
    {
        $redis = new \Redis();

        // Set basic options before connection
        $this->setRedisBasicOptions($redis, $options);

        return $redis;
    }

    /**
     * Creates Redis configuration object from options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return RedisConfig Configuration object
     */
    private function createRedisConfig(array $options): RedisConfig
    {
        return RedisConfig::fromArray($options + $this->getDefaultOptions());
    }

    /**
     * Resolves logger from options or driver manager.
     *
     * @param array<string, mixed> $options Configuration options
     * @param DriverManager $manager Driver manager
     *
     * @return LoggerInterface Logger instance
     */
    private function resolveLogger(array $options, DriverManager $manager): LoggerInterface
    {
        // Priority: explicit option > manager serializer > null logger
        if (isset($options['logger']) && $options['logger'] instanceof LoggerInterface) {
            return $options['logger'];
        }

        return new NullLogger();
    }

    /**
     * Returns default configuration options.
     *
     * @return array<string, mixed> Default options
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

    /**
     * Validates required configuration options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @throws ConfigurationException When required options are missing or invalid
     */
    private function validateRequiredOptions(array $options): void
    {
        $required = ['host'];

        foreach ($required as $option) {
            if (!isset($options[$option]) || empty($options[$option])) {
                throw ConfigurationException::invalidConfiguration($option, 'Required option is missing or empty');
            }
        }
    }

    /**
     * Validates connection-specific options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @throws ConfigurationException When connection options are invalid
     */
    private function validateConnectionOptions(array $options): void
    {
        // Validate host format
        if (isset($options['host'])) {
            if (!is_string($options['host']) || empty(trim($options['host']))) {
                throw ConfigurationException::invalidHost($options['host']);
            }
        }

        // Validate port range
        if (isset($options['port'])) {
            $port = (int) $options['port'];
            if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
                throw ConfigurationException::invalidPort($port, self::MIN_PORT, self::MAX_PORT);
            }
        }

        // Validate timeout values
        $timeoutOptions = ['timeout', 'read_timeout'];
        foreach ($timeoutOptions as $option) {
            if (isset($options[$option])) {
                $timeout = (float) $options[$option];
                if ($timeout <= 0) {
                    throw ConfigurationException::invalidConfiguration($option, 'Timeout must be a positive number');
                }
            }
        }

        // Validate persistent connection settings
        if (!empty($options['persistent']) && empty($options['persistent_id'])) {
            throw ConfigurationException::invalidConfiguration('persistent_id', 'Persistent ID is required when using persistent connections');
        }
    }

    /**
     * Validates optional configuration options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @throws ConfigurationException When optional options are invalid
     */
    private function validateOptionalOptions(array $options): void
    {
        // Validate database number
        if (isset($options['database'])) {
            $database = (int) $options['database'];
            if ($database < 0 || $database > 15) {
                throw ConfigurationException::invalidConfiguration('database', 'Database must be between 0 and 15');
            }
        }

        // Validate serializer
        if (isset($options['serializer'])) {
            $validSerializers = ['php', 'igbinary', 'json'];
            if (!in_array($options['serializer'], $validSerializers, true)) {
                throw ConfigurationException::invalidConfiguration('serializer', 'Serializer must be one of: ' . implode(', ', $validSerializers));
            }
        }

        // Validate retry settings
        if (isset($options['retry_attempts'])) {
            $attempts = (int) $options['retry_attempts'];
            if ($attempts < 0 || $attempts > 10) {
                throw ConfigurationException::invalidConfiguration('retry_attempts', 'Retry attempts must be between 0 and 10');
            }
        }

        // Validate prefix strings
        $prefixes = ['key_prefix', 'tag_prefix', 'topic_prefix'];
        foreach ($prefixes as $prefix) {
            if (isset($options[$prefix]) && !is_string($options[$prefix])) {
                throw ConfigurationException::invalidConfiguration($prefix, 'Prefix must be a string');
            }
        }
    }

    /**
     * Validates security-related options.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @throws ConfigurationException When security options are invalid
     */
    private function validateSecurityOptions(array $options): void
    {
        // Validate password if provided
        if (isset($options['password']) && !is_string($options['password'])) {
            throw ConfigurationException::invalidConfiguration('password', 'Password must be a string');
        }

        // Validate username if provided (Redis 6+)
        if (isset($options['username']) && !is_string($options['username'])) {
            throw ConfigurationException::invalidConfiguration('username', 'Username must be a string');
        }

        // Validate SSL context if provided
        if (isset($options['ssl_context']) && !is_array($options['ssl_context'])) {
            throw ConfigurationException::invalidConfiguration('ssl_context', 'SSL context must be an array');
        }
    }

    /**
     * Sets basic Redis options before connection.
     *
     * @param \Redis $redis Redis instance
     * @param array<string, mixed> $options Configuration options
     */
    private function setRedisBasicOptions(\Redis $redis, array $options): void
    {
        // Set serialization mode
        if (isset($options['serializer'])) {
            $serializerMode = match ($options['serializer']) {
                'php' => \Redis::SERIALIZER_PHP,
                'igbinary' => \Redis::SERIALIZER_IGBINARY,
                'json' => \Redis::SERIALIZER_JSON,
                default => \Redis::SERIALIZER_PHP,
            };
            $redis->setOption(\Redis::OPT_SERIALIZER, $serializerMode);
        }

        // Set compression if enabled and available
        if (!empty($options['compression']) && defined('\Redis::OPT_COMPRESSION')) {
            $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZF);
        }

        // Set scan options
        if (defined('\Redis::OPT_SCAN')) {
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        }
    }

    /**
     * Configures Redis instance after creation.
     *
     * @param \Redis $redis Redis instance
     * @param array<string, mixed> $options Configuration options
     *
     * @throws ConfigurationException When configuration fails
     */
    private function configureRedisInstance(\Redis $redis, array $options): void
    {
        try {
            // Set read timeout if specified
            if (isset($options['read_timeout'])) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, (float) $options['read_timeout']);
            }

            // Additional post-connection configuration can be added here
            // e.g., client name, keepalive settings, etc.
        } catch (\RedisException $e) {
            throw ConfigurationException::invalidConfiguration('redis_configuration', 'Failed to configure Redis instance: ' . $e->getMessage());
        }
    }

    /**
     * Performs health check on Redis connection.
     *
     * @param RedisConnectionManager $connectionManager Connection manager
     *
     * @throws ConfigurationException When health check fails
     */
    private function performHealthCheck(RedisConnectionManager $connectionManager): void
    {
        if (!$connectionManager->isConnected()) {
            throw ConfigurationException::connectionTimeout('redis', (int) self::DEFAULT_TIMEOUT);
        }

        if (!$connectionManager->isHealthy()) {
            throw ConfigurationException::invalidConfiguration('redis_health', 'Redis connection health check failed');
        }
    }
}
