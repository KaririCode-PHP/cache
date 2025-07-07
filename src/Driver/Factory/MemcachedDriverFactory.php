<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\MemcachedDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Memcached driver factory with connection pooling.
 */
final class MemcachedDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        if (!extension_loaded('memcached')) {
            throw ConfigurationException::invalidConfiguration('memcached', 'Memcached extension is not loaded');
        }

        $memcached = new \Memcached($options['persistent_id'] ?? null);

        // Add servers if not already connected (for persistent connections)
        if (empty($memcached->getServerList())) {
            $servers = $options['servers'] ?? [['localhost', 11211]];
            $memcached->addServers($servers);
        }

        // Configure authentication
        if (isset($options['username'], $options['password'])) {
            $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $memcached->setSaslAuthData($options['username'], $options['password']);
        }

        // Performance optimizations
        $defaultOptions = [
            \Memcached::OPT_COMPRESSION => $options['compression'] ?? true,
            \Memcached::OPT_SERIALIZER => $this->getSerializer($options['serializer'] ?? 'php'),
            \Memcached::OPT_HASH => \Memcached::HASH_MURMUR,
            \Memcached::OPT_DISTRIBUTION => \Memcached::DISTRIBUTION_CONSISTENT,
            \Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
            \Memcached::OPT_NO_BLOCK => true,
            \Memcached::OPT_TCP_NODELAY => true,
            \Memcached::OPT_CONNECT_TIMEOUT => ($options['connect_timeout'] ?? 1) * 1000,
            \Memcached::OPT_POLL_TIMEOUT => ($options['poll_timeout'] ?? 1) * 1000,
            \Memcached::OPT_RECV_TIMEOUT => ($options['recv_timeout'] ?? 1) * 1000000,
            \Memcached::OPT_SEND_TIMEOUT => ($options['send_timeout'] ?? 1) * 1000000,
        ];

        // Apply custom options
        if (isset($options['options']) && is_array($options['options'])) {
            $defaultOptions = array_merge($defaultOptions, $options['options']);
        }

        foreach ($defaultOptions as $option => $value) {
            $memcached->setOption($option, $value);
        }

        return new MemcachedDriver($memcached, $options['key_prefix'] ?? 'cache:');
    }

    public function getSupportedOptions(): array
    {
        return [
            'servers', 'persistent_id', 'username', 'password', 'compression', 'serializer',
            'connect_timeout', 'poll_timeout', 'recv_timeout', 'send_timeout', 'key_prefix', 'options',
        ];
    }

    public function validateOptions(array $options): bool
    {
        if (isset($options['servers']) && !is_array($options['servers'])) {
            return false;
        }

        if (isset($options['serializer']) && !in_array($options['serializer'], ['php', 'igbinary', 'json'])) {
            return false;
        }

        return true;
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
