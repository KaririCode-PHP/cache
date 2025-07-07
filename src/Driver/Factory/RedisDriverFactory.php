<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Redis driver factory with connection pooling and optimization.
 */
final class RedisDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        if (!extension_loaded('redis')) {
            throw ConfigurationException::invalidConfiguration('redis', 'Redis extension is not loaded');
        }

        $redis = new \Redis();

        $host = $options['host'] ?? 'localhost';
        $port = $options['port'] ?? 6379;
        $timeout = $options['timeout'] ?? 2.0;
        $persistent = $options['persistent'] ?? false;

        // Connect with persistence support
        if ($persistent) {
            $persistentId = $options['persistent_id'] ?? 'kariricode_cache';
            $connected = $redis->pconnect($host, $port, $timeout, $persistentId);
        } else {
            $connected = $redis->connect($host, $port, $timeout);
        }

        if (!$connected) {
            throw ConfigurationException::invalidConfiguration('redis', "Failed to connect to Redis at {$host}:{$port}");
        }

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
        if ($options['compression'] ?? false) {
            $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZ4);
        }

        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis->setOption(\Redis::OPT_PREFIX, $options['key_prefix'] ?? 'cache:');

        // Optimize for performance
        $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, $options['read_timeout'] ?? 2.0);

        return new RedisDriver(
            $redis,
            $options['key_prefix'] ?? 'cache:',
            $options['tag_prefix'] ?? 'tag:',
            $options['topic_prefix'] ?? 'topic:'
        );
    }

    public function getSupportedOptions(): array
    {
        return [
            'host', 'port', 'timeout', 'persistent', 'persistent_id', 'password', 'username',
            'database', 'compression', 'key_prefix', 'tag_prefix', 'topic_prefix', 'read_timeout',
        ];
    }

    public function validateOptions(array $options): bool
    {
        if (isset($options['port']) && (!is_int($options['port']) || $options['port'] <= 0 || $options['port'] > 65535)) {
            return false;
        }

        if (isset($options['timeout']) && (!is_float($options['timeout']) && !is_int($options['timeout']) || $options['timeout'] <= 0)) {
            return false;
        }

        if (isset($options['database']) && (!is_int($options['database']) || $options['database'] < 0)) {
            return false;
        }

        return true;
    }
}
