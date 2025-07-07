<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\MemcachedDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Memcached driver factory with connection pooling.
 */
final class MemcachedDriverFactory implements CacheDriverFactory
{
    public function create(array $config): CacheDriver
    {
        if (!extension_loaded('memcached')) {
            throw ConfigurationException::invalidConfiguration('memcached', 'Memcached extension is not loaded');
        }

        $memcached = new \Memcached($config['persistent_id'] ?? null);

        // Add servers if not already connected (for persistent connections)
        if (empty($memcached->getServerList())) {
            $servers = $config['servers'] ?? [['localhost', 11211]];
            $memcached->addServers($servers);
        }

        // Configure authentication
        if (isset($config['username'], $config['password'])) {
            $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $memcached->setSaslAuthData($config['username'], $config['password']);
        }

        // Performance optimizations
        $options = [
            \Memcached::OPT_COMPRESSION => $config['compression'] ?? true,
            \Memcached::OPT_SERIALIZER => $this->getSerializer($config['serializer'] ?? 'php'),
            \Memcached::OPT_HASH => \Memcached::HASH_MURMUR,
            \Memcached::OPT_DISTRIBUTION => \Memcached::DISTRIBUTION_CONSISTENT,
            \Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
            \Memcached::OPT_NO_BLOCK => true,
            \Memcached::OPT_TCP_NODELAY => true,
            \Memcached::OPT_CONNECT_TIMEOUT => ($config['connect_timeout'] ?? 1) * 1000,
            \Memcached::OPT_POLL_TIMEOUT => ($config['poll_timeout'] ?? 1) * 1000,
            \Memcached::OPT_RECV_TIMEOUT => ($config['recv_timeout'] ?? 1) * 1000000,
            \Memcached::OPT_SEND_TIMEOUT => ($config['send_timeout'] ?? 1) * 1000000,
        ];

        // Apply custom options
        if (isset($config['options']) && is_array($config['options'])) {
            $options = array_merge($options, $config['options']);
        }

        foreach ($options as $option => $value) {
            $memcached->setOption($option, $value);
        }

        return new MemcachedDriver($memcached, $config['key_prefix'] ?? 'cache:');
    }

    private function getSerializer(string $serializer): int
    {
        return match ($serializer) {
            'php' => \Memcached::SERIALIZER_PHP,
            'igbinary' => \Memcached::SERIALIZER_IGBINARY,
            'json' => \Memcached::SERIALIZER_JSON,
            default => \Memcached::SERIALIZER_PHP,
        };
    }
}
