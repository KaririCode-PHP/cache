<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Transaction\DefaultTransactionManager;

class RedisDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $driverManager): CacheDriver
    {
        var_dump($options, $driverManager);

        $redis = new \Redis();
        $host = $options['host'] ?? 'localhost';
        $port = $options['port'] ?? 6379;
        $timeout = $options['timeout'] ?? 0.0;

        if (!$redis->connect($host, $port, $timeout)) {
            throw new ConfigurationException("Failed to connect to Redis at $host:$port");
        }

        if (isset($options['password'])) {
            $redis->auth($options['password']);
        }

        if (isset($options['database'])) {
            $redis->select($options['database']);
        }

        $transactionManager = $driverManager->getTransactionManager();
        if (!$transactionManager) {
            $transactionManager = new DefaultTransactionManager();
        }

        return new RedisDriver($redis, $transactionManager);
    }
}
