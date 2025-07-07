<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Redis driver factory with connection pooling and optimization.
 */
final class RedisDriverFactory implements CacheDriverFactory
{
    public function create(array $config): CacheDriver
    {
        $redis = new \Redis();

        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 2.0;
        $persistent = $config['persistent'] ?? false;

        // Connect with persistence support
        if ($persistent) {
            $persistentId = $config['persistent_id'] ?? 'kariricode_cache';
            $connected = $redis->pconnect($host, $port, $timeout, $persistentId);
        } else {
            $connected = $redis->connect($host, $port, $timeout);
        }

        if (!$connected) {
            throw ConfigurationException::invalidConfiguration('redis', "Failed to connect to Redis at {$host}:{$port}");
        }

        // Authentication
        if (isset($config['password'])) {
            if (isset($config['username'])) {
                $redis->auth([$config['username'], $config['password']]);
            } else {
                $redis->auth($config['password']);
            }
        }

        // Select database
        if (isset($config['database'])) {
            $redis->select($config['database']);
        }

        // Configure serialization
        if ($config['compression'] ?? false) {
            $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZ4);
        }

        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis->setOption(\Redis::OPT_PREFIX, $config['key_prefix'] ?? 'cache:');

        // Optimize for performance
        $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, $config['read_timeout'] ?? 2.0);

        return new RedisDriver(
            $redis,
            $config['key_prefix'] ?? 'cache:',
            $config['tag_prefix'] ?? 'tag:',
            $config['topic_prefix'] ?? 'topic:'
        );
    }
}
