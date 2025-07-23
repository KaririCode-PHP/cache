<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Config;

use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Immutable configuration value object for Redis driver.
 * Follows Value Object pattern and encapsulates all configuration logic.
 */
final class RedisConfig
{
    public function __construct(
        public readonly string $keyPrefix = 'cache:',
        public readonly string $tagPrefix = 'tag:',
        public readonly string $topicPrefix = 'topic:',
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 6379,
        public readonly float $timeout = 2.0,
        public readonly int $retryAttempts = 3,
        public readonly float $retryDelay = 0.1,
        public readonly bool $persistent = false,
        public readonly ?string $persistentId = null,
        public readonly int $retryInterval = 0,
        public readonly float $readTimeout = 0.0,
        public readonly int $scanBatchSize = 500,
        public readonly ?string $password = null,
        public readonly int $database = 0
    ) {
        if ($port < 1 || $port > 65535) {
            throw ConfigurationException::invalidPort($port, 1, 65535);
        }

        if ($retryAttempts < 0) {
            throw ConfigurationException::invalidOption('retry_attempts', $retryAttempts, 'non-negative integer');
        }

        if ($retryDelay < 0) {
            throw ConfigurationException::invalidOption('retry_delay', $retryDelay, 'non-negative float');
        }

        if ($scanBatchSize <= 0) {
            throw ConfigurationException::invalidOption('scan_batch_size', $scanBatchSize, 'positive integer');
        }

        if ($timeout <= 0) {
            throw ConfigurationException::invalidOption('timeout', $timeout, 'positive float');
        }

        if ($database < 0) {
            throw ConfigurationException::invalidOption('database', $database, 'non-negative integer');
        }
    }

    public static function fromArray(array $config): self
    {
        return new self(
            keyPrefix: $config['key_prefix'] ?? 'cache:',
            tagPrefix: $config['tag_prefix'] ?? 'tag:',
            topicPrefix: $config['topic_prefix'] ?? 'topic:',
            host: $config['host'] ?? '127.0.0.1',
            port: (int) ($config['port'] ?? 6379),
            timeout: (float) ($config['timeout'] ?? 2.0),
            retryAttempts: (int) ($config['retry_attempts'] ?? 3),
            retryDelay: (float) ($config['retry_delay'] ?? 0.1),
            persistent: (bool) ($config['persistent'] ?? false),
            persistentId: $config['persistent_id'] ?? null,
            retryInterval: (int) ($config['retry_interval'] ?? 0),
            readTimeout: (float) ($config['read_timeout'] ?? 0.0),
            scanBatchSize: (int) ($config['scan_batch_size'] ?? 500),
            password: $config['password'] ?? null,
            database: (int) ($config['database'] ?? 0)
        );
    }

    /**
     * Validates the complete configuration for consistency.
     */
    public function validate(): void
    {
        // Validações adicionais de consistência entre configurações
        if ($this->persistent && null === $this->persistentId) {
            throw ConfigurationException::invalidConfiguration('persistent_id', 'Persistent ID is required when using persistent connections');
        }

        if ($this->readTimeout > 0 && $this->readTimeout <= $this->timeout) {
            throw ConfigurationException::invalidConfiguration('read_timeout', 'Read timeout should be greater than connection timeout');
        }
    }
}
