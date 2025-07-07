<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config;

use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Modern cache configuration manager with environment support.
 */
final class CacheConfig
{
    private array $config;
    private array $environment;

    public function __construct(array $config = [], array $environment = [])
    {
        $this->environment = $environment ?: $_ENV;
        $this->config = $this->processConfig($config);
    }

    /**
     * Create from file with environment variable substitution.
     */
    public static function fromFile(string $path, array $environment = []): self
    {
        if (!file_exists($path)) {
            throw ConfigurationException::invalidConfiguration('file', "Config file not found: {$path}");
        }

        $config = require $path;

        if (!is_array($config)) {
            throw ConfigurationException::invalidConfiguration('file', 'Config file must return an array');
        }

        return new self($config, $environment);
    }

    /**
     * Create from array with sensible defaults.
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Create default development configuration.
     */
    public static function development(): self
    {
        return new self([
            'default_ttl' => 3600,
            'layers' => [
                [
                    'driver' => 'array',
                    'priority' => 5,
                    'config' => [],
                ],
            ],
            'serializer' => 'native',
            'stampede_protection' => true,
            'metrics' => true,
            'sliding_expiration' => false,
        ]);
    }

    /**
     * Create default production configuration.
     */
    public static function production(): self
    {
        return new self([
            'default_ttl' => 3600,
            'layers' => [
                [
                    'driver' => 'apcu',
                    'priority' => 5,
                    'config' => [],
                ],
                [
                    'driver' => 'redis',
                    'priority' => 10,
                    'config' => [
                        'host' => '${REDIS_HOST:localhost}',
                        'port' => '${REDIS_PORT:6379}',
                        'password' => '${REDIS_PASSWORD:}',
                        'database' => '${REDIS_DB:0}',
                        'persistent' => true,
                        'compression' => true,
                    ],
                ],
                [
                    'driver' => 'filesystem',
                    'priority' => 90,
                    'config' => [
                        'directory' => '${CACHE_DIR:/tmp/cache}',
                    ],
                ],
            ],
            'serializer' => 'igbinary',
            'stampede_protection' => true,
            'metrics' => true,
            'sliding_expiration' => true,
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getLayers(): array
    {
        return $this->config['layers'] ?? [];
    }

    public function getSerializer(): string
    {
        return $this->config['serializer'] ?? 'native';
    }

    public function isStampedeProtectionEnabled(): bool
    {
        return $this->config['stampede_protection'] ?? true;
    }

    public function isMetricsEnabled(): bool
    {
        return $this->config['metrics'] ?? true;
    }

    public function isSlidingExpirationEnabled(): bool
    {
        return $this->config['sliding_expiration'] ?? false;
    }

    public function getDefaultTtl(): int
    {
        return $this->config['default_ttl'] ?? 3600;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    private function processConfig(array $config): array
    {
        return $this->resolveEnvironmentVariables($config);
    }

    private function resolveEnvironmentVariables(array $config): array
    {
        array_walk_recursive($config, function (&$value) {
            if (is_string($value) && preg_match('/^\$\{([^:}]+)(?::([^}]*))?\}$/', $value, $matches)) {
                $envKey = $matches[1];
                $defaultValue = $matches[2] ?? '';

                $envValue = $this->environment[$envKey] ?? $defaultValue;

                // Type conversion
                if (is_numeric($envValue)) {
                    $value = str_contains($envValue, '.') ? (float) $envValue : (int) $envValue;
                } elseif (in_array(strtolower($envValue), ['true', 'false'])) {
                    $value = 'true' === strtolower($envValue);
                } else {
                    $value = $envValue;
                }
            }
        });

        return $config;
    }
}
