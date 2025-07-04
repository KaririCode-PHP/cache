<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\MemcachedDriver;
use KaririCode\Cache\Transaction\DefaultTransactionManager;

class MemcachedDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $driverManager): CacheDriver
    {
        $memcached = new \Memcached();

        $servers = $options['servers'] ?? [['localhost', 11211]];
        $memcached->addServers($servers);

        if (isset($options['username']) && isset($options['password'])) {
            $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $memcached->setSaslAuthData($options['username'], $options['password']);
        }

        if (isset($options['options']) && is_array($options['options'])) {
            foreach ($options['options'] as $optionKey => $optionValue) {
                $memcached->setOption($optionKey, $optionValue);
            }
        }

        $transactionManager = $driverManager->getTransactionManager();
        if (!$transactionManager) {
            $transactionManager = new DefaultTransactionManager();
        }

        return new MemcachedDriver($memcached, $transactionManager);
    }
}
