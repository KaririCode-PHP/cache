<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Driver\MemcachedDriver;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Enhanced Memcached driver factory with cluster support.
 */
final class MemcachedDriverFactory implements CacheDriverFactory
{
    private const REQUIRED_EXTENSION = 'memcached';
    private const DEFAULT_WEIGHT = 100;
    private const DEFAULT_TIMEOUT = 1;

    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $this->validateExtension();
        $this->validateOptions($options);

        $memcached = $this->createMemcachedInstance($options);
        $this->configureMemcached($memcached, $options);

        return new MemcachedDriver(
            $memcached,
            $options['key_prefix'] ?? 'cache:'
        );
    }

    /**
     * @return list<string>
     */
    public function getSupportedOptions(): array
    {
        return [
            'servers', 'persistent_id', 'username', 'password', 'compression',
            'serializer', 'connect_timeout', 'poll_timeout', 'recv_timeout',
            'send_timeout', 'key_prefix', 'options', 'sasl_auth',
        ];
    }

    public function validateOptions(array $options): bool
    {
        $errors = [];

        // Validate servers
        if (isset($options['servers'])) {
            if (!is_array($options['servers'])) {
                $errors[] = 'servers must be an array';
            } else {
                foreach ($options['servers'] as $index => $server) {
                    if (!is_array($server) || count($server) < 2) {
                        $errors[] = "servers[{$index}] must be an array with at least 2 elements [host, port]";
                    }
                }
            }
        }

        // Validate serializer
        if (isset($options['serializer'])) {
            $validSerializers = ['php', 'igbinary', 'json'];
            if (!in_array($options['serializer'], $validSerializers)) {
                $errors[] = 'serializer must be one of: ' . implode(', ', $validSerializers);
            }
        }

        // Validate timeout options
        $timeoutOptions = ['connect_timeout', 'poll_timeout', 'recv_timeout', 'send_timeout'];
        foreach ($timeoutOptions as $option) {
            if (isset($options[$option])) {
                if (!is_int($options[$option]) || $options[$option] < 1) {
                    $errors[] = "{$option} must be a positive integer";
                }
            }
        }

        if (!empty($errors)) {
            throw ConfigurationException::invalidConfiguration('memcached', implode(', ', $errors));
        }

        return true;
    }

    private function validateExtension(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    private function createMemcachedInstance(array $options): \Memcached
    {
        $persistentId = $options['persistent_id'] ?? null;
        $memcached = new \Memcached($persistentId);

        // Add servers if not already connected (for persistent connections)
        if (empty($memcached->getServerList())) {
            $servers = $options['servers'] ?? [['localhost', 11211, self::DEFAULT_WEIGHT]];

            // Normalize server format
            $normalizedServers = [];
            foreach ($servers as $server) {
                $normalizedServers[] = [
                    $server[0], // host
                    $server[1], // port
                    $server[2] ?? self::DEFAULT_WEIGHT, // weight
                ];
            }

            if (!$memcached->addServers($normalizedServers)) {
                throw ConfigurationException::connectionTimeout('memcached', self::DEFAULT_TIMEOUT);
            }
        }

        return $memcached;
    }

    private function configureMemcached(\Memcached $memcached, array $options): void
    {
        // Configure authentication if provided
        if (isset($options['username'], $options['password'])) {
            $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $memcached->setSaslAuthData($options['username'], $options['password']);
        }

        // Default options with performance optimizations
        $defaultOptions = [
            \Memcached::OPT_COMPRESSION => $options['compression'] ?? true,
            \Memcached::OPT_SERIALIZER => $this->getSerializerOption($options['serializer'] ?? 'php'),
            \Memcached::OPT_HASH => \Memcached::HASH_MURMUR,
            \Memcached::OPT_DISTRIBUTION => \Memcached::DISTRIBUTION_CONSISTENT,
            \Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
            \Memcached::OPT_NO_BLOCK => true,
            \Memcached::OPT_TCP_NODELAY => true,
            \Memcached::OPT_CONNECT_TIMEOUT => ($options['connect_timeout'] ?? self::DEFAULT_TIMEOUT) * 1000,
            \Memcached::OPT_POLL_TIMEOUT => ($options['poll_timeout'] ?? self::DEFAULT_TIMEOUT) * 1000,
            \Memcached::OPT_RECV_TIMEOUT => ($options['recv_timeout'] ?? self::DEFAULT_TIMEOUT) * 1000000,
            \Memcached::OPT_SEND_TIMEOUT => ($options['send_timeout'] ?? self::DEFAULT_TIMEOUT) * 1000000,
        ];

        // Apply custom options
        if (isset($options['options']) && is_array($options['options'])) {
            $defaultOptions = array_merge($defaultOptions, $options['options']);
        }

        foreach ($defaultOptions as $option => $value) {
            $memcached->setOption($option, $value);
        }

        // Test connection
        $version = $memcached->getVersion();
        if (empty($version)) {
            throw ConfigurationException::connectionTimeout('memcached', self::DEFAULT_TIMEOUT);
        }
    }

    private function getSerializerOption(string $serializer): int
    {
        return match ($serializer) {
            'php' => \Memcached::SERIALIZER_PHP,
            'igbinary' => \Memcached::SERIALIZER_IGBINARY,
            'json' => \Memcached::SERIALIZER_JSON,
            default => \Memcached::SERIALIZER_PHP,
        };
    }
}
