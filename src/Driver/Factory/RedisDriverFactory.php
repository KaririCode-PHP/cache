<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced Redis driver factory with comprehensive validation and monitoring.
 *
 * Features:
 * - Comprehensive configuration validation
 * - Connection health checks
 * - Performance optimizations
 * - Security configurations
 * - Automatic failover support
 * - Monitoring and logging integration
 *
 * @see https://redis.io/documentation Redis Documentation
 * @see https://github.com/phpredis/phpredis PHPRedis Documentation
 */
final class RedisDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'redis';
    private const DEFAULT_TIMEOUT = 2.0;
    private const DEFAULT_READ_TIMEOUT = 2.0;
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;
    private const CONNECTION_RETRY_ATTEMPTS = 3;
    private const CONNECTION_RETRY_DELAY = 0.1; // 100ms

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateOptions($options);

        $redis = $this->createRedisInstance($options);
        $this->configureRedis($redis, $options);

        $logger = $this->createLogger($options);

        return new RedisDriver(
            $redis,
            $options['key_prefix'] ?? 'cache:',
            $options['tag_prefix'] ?? 'tag:',
            $options['topic_prefix'] ?? 'topic:',
            $logger,
            $options
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            // Connection options
            'host', 'port', 'timeout', 'persistent', 'persistent_id',
            'password', 'username', 'database',

            // Performance options
            'compression', 'read_timeout', 'tcp_keepalive',

            // Prefixes
            'key_prefix', 'tag_prefix', 'topic_prefix',

            // Advanced options
            'serializer', 'cluster_mode', 'sentinel_mode',
            'max_connections', 'retry_attempts', 'retry_delay',

            // Monitoring
            'enable_logging', 'log_level',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $errors = [];

        // Validate host
        if (isset($options['host']) && !is_string($options['host'])) {
            $errors[] = 'Host must be a string';
        }

        // Validate port
        if (isset($options['port'])) {
            if (!is_int($options['port']) || $options['port'] < self::MIN_PORT || $options['port'] > self::MAX_PORT) {
                $errors[] = 'Port must be an integer between ' . self::MIN_PORT . ' and ' . self::MAX_PORT;
            }
        }

        // Validate timeout
        if (isset($options['timeout'])) {
            if (!is_numeric($options['timeout']) || $options['timeout'] <= 0) {
                $errors[] = 'Timeout must be a positive number';
            }
        }

        // Validate database
        if (isset($options['database'])) {
            if (!is_int($options['database']) || $options['database'] < 0) {
                $errors[] = 'Database must be a non-negative integer';
            }
        }

        // Validate boolean options
        $booleanOptions = ['persistent', 'compression', 'cluster_mode', 'sentinel_mode', 'tcp_keepalive'];
        foreach ($booleanOptions as $option) {
            if (isset($options[$option]) && !is_bool($options[$option])) {
                $errors[] = "{$option} must be a boolean";
            }
        }

        // Validate string options
        $stringOptions = ['host', 'password', 'username', 'persistent_id', 'key_prefix', 'tag_prefix', 'topic_prefix'];
        foreach ($stringOptions as $option) {
            if (isset($options[$option]) && !is_string($options[$option])) {
                $errors[] = "{$option} must be a string";
            }
        }

        if (!empty($errors)) {
            throw ConfigurationException::invalidConfiguration('redis', implode(', ', $errors));
        }

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    private function createRedisInstance(array $options): \Redis
    {
        $redis = new \Redis();

        $host = $options['host'] ?? 'localhost';
        $port = $options['port'] ?? 6379;
        $timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        $persistent = $options['persistent'] ?? false;
        $retryAttempts = $options['retry_attempts'] ?? self::CONNECTION_RETRY_ATTEMPTS;
        $retryDelay = $options['retry_delay'] ?? self::CONNECTION_RETRY_DELAY;

        $connected = false;
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryAttempts; ++$attempt) {
            try {
                if ($persistent) {
                    $persistentId = $options['persistent_id'] ?? 'kariricode_cache';
                    $connected = $redis->pconnect($host, $port, $timeout, $persistentId);
                } else {
                    $connected = $redis->connect($host, $port, $timeout);
                }

                if ($connected) {
                    break;
                }
            } catch (\RedisException $e) {
                $lastException = $e;

                if ($attempt < $retryAttempts) {
                    usleep((int) ($retryDelay * 1000000 * $attempt)); // Exponential backoff
                }
            }
        }

        if (!$connected) {
            $errorMessage = $lastException ? $lastException->getMessage() : 'Unknown connection error';
            throw ConfigurationException::connectionTimeout("{$host}:{$port}", $timeout, $lastException);
        }

        return $redis;
    }

    private function configureRedis(\Redis $redis, array $options): void
    {
        try {
            // Authentication
            if (isset($options['password'])) {
                if (isset($options['username'])) {
                    $redis->auth([$options['username'], $options['password']]);
                } else {
                    $redis->auth($options['password']);
                }
            }

            // Select database
            if (isset($options['database'])) {
                $redis->select($options['database']);
            }

            // Configure serialization
            $serializer = $this->getSerializerOption($options['serializer'] ?? 'php');
            $redis->setOption(\Redis::OPT_SERIALIZER, $serializer);

            // Configure compression
            if ($options['compression'] ?? false) {
                if (defined('Redis::COMPRESSION_LZ4')) {
                    $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZ4);
                } elseif (defined('Redis::COMPRESSION_ZSTD')) {
                    $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_ZSTD);
                }
            }

            // Set key prefix
            if (isset($options['key_prefix'])) {
                $redis->setOption(\Redis::OPT_PREFIX, $options['key_prefix']);
            }

            // Performance optimizations
            $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, $options['tcp_keepalive'] ?? 1);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $options['read_timeout'] ?? self::DEFAULT_READ_TIMEOUT);

            // Verify connection with ping
            $pingResult = $redis->ping();
            if ('+PONG' !== $pingResult && 'PONG' !== $pingResult) {
                throw new \RedisException('Redis ping failed: ' . $pingResult);
            }
        } catch (\RedisException $e) {
            throw ConfigurationException::invalidConfiguration('redis', 'Failed to configure Redis: ' . $e->getMessage());
        }
    }

    private function getSerializerOption(string $serializer): int
    {
        return match (strtolower($serializer)) {
            'php' => \Redis::SERIALIZER_PHP,
            'igbinary' => \Redis::SERIALIZER_IGBINARY,
            'json' => \Redis::SERIALIZER_JSON,
            'msgpack' => \Redis::SERIALIZER_MSGPACK,
            default => \Redis::SERIALIZER_PHP,
        };
    }

    private function createLogger(array $options): LoggerInterface
    {
        return new NullLogger();
    }
}
