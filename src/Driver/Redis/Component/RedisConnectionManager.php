<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Component;

use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * Handles Redis connection lifecycle management.
 * Single Responsibility: Connection management only.
 */
final class RedisConnectionManager
{
    private bool $connected = false;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private LoggerInterface $logger
    ) {
        $this->initialize();
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function isHealthy(): bool
    {
        try {
            $this->redis->ping();

            return true;
        } catch (\RedisException) {
            return false;
        }
    }

    public function reconnect(): void
    {
        try {
            $this->connect();
            $this->connected = true;
            $this->logger->info('Redis reconnection successful');
        } catch (\RedisException $e) {
            $this->connected = false;
            $this->logger->error('Redis reconnection failed', ['error' => $e->getMessage()]);
            throw CacheException::operationFailed('reconnect', '', $e);
        }
    }

    public function ensureConnection(): void
    {
        if (!$this->connected) {
            $this->reconnect();
        }
    }

    private function initialize(): void
    {
        try {
            $this->connect();
            $this->connected = true;
        } catch (\RedisException $e) {
            $this->connected = false;
            $this->logger->error('Initial Redis connection failed', ['error' => $e->getMessage()]);
        }
    }

    private function connect(): void
    {
        try {
            if ($this->config->persistent) {
                $result = $this->redis->pconnect(
                    $this->config->host,
                    $this->config->port,
                    $this->config->timeout,
                    $this->config->persistentId,
                    $this->config->retryInterval,
                    $this->config->readTimeout
                );
            } else {
                $result = $this->redis->connect(
                    $this->config->host,
                    $this->config->port,
                    $this->config->timeout,
                    null,
                    $this->config->retryInterval,
                    $this->config->readTimeout
                );
            }

            if (!$result) {
                throw ConfigurationException::connectionTimeout('redis', (int) $this->config->timeout);
            }

            // Authenticate if password is provided
            if (null !== $this->config->password) {
                if (!$this->redis->auth($this->config->password)) {
                    throw ConfigurationException::invalidConfiguration('password', 'Authentication failed with provided password');
                }
            }

            // Select database if specified
            if (0 !== $this->config->database) {
                if (!$this->redis->select($this->config->database)) {
                    throw ConfigurationException::invalidConfiguration('database', "Failed to select database {$this->config->database}");
                }
            }
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('connect', '', $e);
        }
    }
}
