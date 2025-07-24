<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Config;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * Immutable configuration value object for the Memcached driver.
 * This DTO ensures that all configuration values are of the correct type.
 */
final class MemcachedConfig implements ImmutableValue
{
    private const MIN_TIMEOUT = 100; // milliseconds
    private const MAX_TIMEOUT = 30000; // milliseconds
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;
    private const DEFAULT_WEIGHT = 100;
    private const DEFAULT_PORT = 11211;

    /**
     * @param list<array{0: string, 1: int, 2: int}> $servers
     * @param array<array-key, mixed> $options
     */
    public function __construct(
        public readonly array $servers,
        public readonly string $keyPrefix,
        public readonly ?string $persistentId,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly bool $compression,
        public readonly string $serializer,
        public readonly int $connectTimeout,
        public readonly int $pollTimeout,
        public readonly int $recvTimeout,
        public readonly int $sendTimeout,
        public readonly array $options,
        public readonly bool $saslAuth
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // This logic is now more explicit to help static analysis tools.
        $serverList = [['localhost', self::DEFAULT_PORT]];
        if (isset($config['servers']) && is_array($config['servers'])) {
            $serverList = $config['servers'];
        }

        return new self(
            servers: self::parseServers($serverList),
            keyPrefix: is_string($config['key_prefix'] ?? null) ? $config['key_prefix'] : 'cache:',
            persistentId: is_string($config['persistent_id'] ?? null) ? $config['persistent_id'] : null,
            username: is_string($config['username'] ?? null) ? $config['username'] : null,
            password: is_string($config['password'] ?? null) ? $config['password'] : null,
            compression: (bool) ($config['compression'] ?? true),
            serializer: is_string($config['serializer'] ?? null) ? $config['serializer'] : 'php',
            connectTimeout: is_int($config['connect_timeout'] ?? null) ? $config['connect_timeout'] : 1000,
            pollTimeout: is_int($config['poll_timeout'] ?? null) ? $config['poll_timeout'] : 1000,
            recvTimeout: is_int($config['recv_timeout'] ?? null) ? $config['recv_timeout'] : 1000,
            sendTimeout: is_int($config['send_timeout'] ?? null) ? $config['send_timeout'] : 1000,
            options: isset($config['options']) && is_array($config['options']) ? $config['options'] : [],
            saslAuth: (bool) ($config['sasl_auth'] ?? false)
        );
    }

    public function validate(): void
    {
        $this->validateServers();
        $this->validateTimeouts();
        $this->validateSerializer();
        $this->validateAuthentication();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'servers' => $this->servers,
            'key_prefix' => $this->keyPrefix,
            'persistent_id' => $this->persistentId,
            'username' => $this->username,
            'password' => $this->password,
            'compression' => $this->compression,
            'serializer' => $this->serializer,
            'connect_timeout' => $this->connectTimeout,
            'poll_timeout' => $this->pollTimeout,
            'recv_timeout' => $this->recvTimeout,
            'send_timeout' => $this->sendTimeout,
            'options' => $this->options,
            'sasl_auth' => $this->saslAuth,
            default => $default
        };
    }

    /**
     * @param array<mixed> $servers
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function parseServers(array $servers): array
    {
        $parsed = [];
        foreach ($servers as $server) {
            if (!is_array($server) || count($server) < 2) {
                throw ConfigurationException::invalidConfiguration('servers', 'Each server must be an array with at least [host, port]');
            }

            $host = (string) ($server[0] ?? '');
            $port = (int) ($server[1] ?? 0);
            $weight = (int) ($server[2] ?? self::DEFAULT_WEIGHT);

            if (empty($host)) {
                throw ConfigurationException::invalidConfiguration('servers', 'Server host cannot be empty');
            }
            if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
                throw ConfigurationException::invalidPort($port, self::MIN_PORT, self::MAX_PORT);
            }

            $parsed[] = [$host, $port, $weight];
        }

        if (empty($parsed)) {
            throw ConfigurationException::invalidConfiguration('servers', 'At least one server must be configured');
        }

        return $parsed;
    }

    private function validateServers(): void
    {
        if (empty($this->servers)) {
            throw ConfigurationException::invalidConfiguration('servers', 'No servers configured');
        }
    }

    private function validateTimeouts(): void
    {
        $timeouts = [
            'connect_timeout' => $this->connectTimeout,
            'poll_timeout' => $this->pollTimeout,
            'recv_timeout' => $this->recvTimeout,
            'send_timeout' => $this->sendTimeout,
        ];

        foreach ($timeouts as $name => $value) {
            if ($value < self::MIN_TIMEOUT || $value > self::MAX_TIMEOUT) {
                $message = 'Timeout must be between ' . self::MIN_TIMEOUT . 'ms and ' . self::MAX_TIMEOUT . 'ms';
                throw ConfigurationException::invalidConfiguration($name, $message);
            }
        }
    }

    private function validateSerializer(): void
    {
        $validSerializers = ['php', 'igbinary', 'json'];
        if (!in_array($this->serializer, $validSerializers, true)) {
            throw ConfigurationException::invalidConfiguration('serializer', 'Serializer must be one of: ' . implode(', ', $validSerializers));
        }
        if ('igbinary' === $this->serializer && !extension_loaded('igbinary')) {
            throw ConfigurationException::missingExtension('igbinary');
        }
    }

    private function validateAuthentication(): void
    {
        if ($this->saslAuth) {
            if (empty($this->username) || empty($this->password)) {
                throw ConfigurationException::invalidConfiguration('sasl_auth', 'Username and password are required when SASL authentication is enabled');
            }
        }
    }

    public function getSerializerOption(): int
    {
        return match ($this->serializer) {
            'igbinary' => \Memcached::SERIALIZER_IGBINARY,
            'json' => \Memcached::SERIALIZER_JSON,
            default => \Memcached::SERIALIZER_PHP,
        };
    }
}
