<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Component;

use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Exception\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * Manages Memcached connection lifecycle.
 * Follows SRP by handling only connection-related operations.
 */
final class MemcachedConnectionManager
{
    private bool $isConnected = false;
    private int $connectionAttempts = 0;
    private ?\DateTimeImmutable $lastConnectionAttempt = null;

    private const MAX_CONNECTION_ATTEMPTS = 3;
    private const CONNECTION_RETRY_DELAY = 0.1; // seconds

    public function __construct(
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Ensures the connection is established and healthy.
     *
     * @throws ConnectionException
     */
    public function ensureConnection(): void
    {
        if ($this->isConnected && $this->isHealthy()) {
            return;
        }

        $this->connect();
    }

    /**
     * Establishes connection to Memcached servers.
     *
     * @throws ConnectionException
     */
    public function connect(): void
    {
        ++$this->connectionAttempts;
        $this->lastConnectionAttempt = new \DateTimeImmutable();

        try {
            // Configure Memcached instance
            $this->configureMemcached();

            // Add servers
            $this->addServers();

            // Test connection
            if (!$this->testConnection()) {
                throw new \RuntimeException('Failed to connect to any Memcached server');
            }

            $this->isConnected = true;
            $this->connectionAttempts = 0;

            $this->logger->info('Successfully connected to Memcached', [
                'servers' => count($this->config->servers),
                'persistent_id' => $this->config->persistentId,
            ]);
        } catch (\Throwable $e) {
            $this->handleConnectionFailure($e);
        }
    }

    /**
     * Reconnects to Memcached servers.
     *
     * @throws ConnectionException
     */
    public function reconnect(): void
    {
        $this->logger->info('Attempting to reconnect to Memcached');

        $this->isConnected = false;
        $this->connectionAttempts = 0;

        // Clear existing servers
        $this->memcached->resetServerList();

        // Add delay before reconnecting
        if (null !== $this->lastConnectionAttempt) {
            $timeSinceLastAttempt = microtime(true) - $this->lastConnectionAttempt->getTimestamp();
            if ($timeSinceLastAttempt < self::CONNECTION_RETRY_DELAY) {
                usleep((int) ((self::CONNECTION_RETRY_DELAY - $timeSinceLastAttempt) * 1000000));
            }
        }

        $this->connect();
    }

    /**
     * Checks if the connection is healthy.
     */
    public function isHealthy(): bool
    {
        if (!$this->isConnected) {
            return false;
        }

        try {
            // Use getVersion as a lightweight health check
            $stats = $this->memcached->getVersion();

            if (empty($stats)) {
                $this->logger->warning('No Memcached servers responded to version check');

                return false;
            }

            // Check if at least one server is responding
            foreach ($stats as $server => $version) {
                if (false !== $version && !empty($version)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Gets connection statistics.
     *
     * @return array<string, mixed>
     */
    public function getConnectionStats(): array
    {
        return [
            'is_connected' => $this->isConnected,
            'connection_attempts' => $this->connectionAttempts,
            'last_connection_attempt' => $this->lastConnectionAttempt?->format(\DateTimeInterface::RFC3339),
            'servers' => $this->getServerStats(),
        ];
    }

    private function configureMemcached(): void
    {
        // Set default options
        $this->memcached->setOption(\Memcached::OPT_COMPRESSION, $this->config->compression);
        $this->memcached->setOption(\Memcached::OPT_SERIALIZER, $this->config->getSerializerOption());
        $this->memcached->setOption(\Memcached::OPT_PREFIX_KEY, $this->config->keyPrefix);

        // Set timeout options
        $this->memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, $this->config->connectTimeout);
        $this->memcached->setOption(\Memcached::OPT_POLL_TIMEOUT, $this->config->pollTimeout);
        $this->memcached->setOption(\Memcached::OPT_RECV_TIMEOUT, $this->config->recvTimeout * 1000); // microseconds
        $this->memcached->setOption(\Memcached::OPT_SEND_TIMEOUT, $this->config->sendTimeout * 1000); // microseconds

        // Set custom options
        foreach ($this->config->options as $option => $value) {
            $this->memcached->setOption($option, $value);
        }

        // Configure SASL authentication if enabled
        if ($this->config->saslAuth) {
            // Check ensures that username and password are not null
            $this->memcached->setSaslAuthData((string) $this->config->username, (string) $this->config->password);
        }
    }

    private function addServers(): void
    {
        if (!empty($this->memcached->getServerList())) {
            return; // Avoid re-adding servers on a persistent connection.
        }
        $this->memcached->addServers($this->config->servers);
    }

    private function testConnection(): bool
    {
        $testKey = '__connection_test_' . uniqid();
        $testValue = 'test';

        // Try to set a test value
        if (!$this->memcached->set($testKey, $testValue, 1)) {
            return false;
        }

        // Try to get the test value
        $retrieved = $this->memcached->get($testKey);

        // Clean up
        $this->memcached->delete($testKey);

        return $retrieved === $testValue;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getServerStats(): array
    {
        $stats = [];
        $versions = $this->memcached->getVersion();

        if (is_array($versions)) {
            foreach ($versions as $server => $version) {
                $stats[$server] = [
                    'version' => $version,
                    'status' => false !== $version ? 'online' : 'offline',
                ];
            }
        }

        return $stats;
    }

    private function handleConnectionFailure(\Throwable $e): void
    {
        $this->isConnected = false;

        $this->logger->error('Failed to connect to Memcached', [
            'attempt' => $this->connectionAttempts,
            'max_attempts' => self::MAX_CONNECTION_ATTEMPTS,
            'error' => $e->getMessage(),
        ]);

        if ($this->connectionAttempts >= self::MAX_CONNECTION_ATTEMPTS) {
            throw ConnectionException::connectionFailed('memcached', 'Max connection attempts reached: ' . $e->getMessage());
        }

        throw ConnectionException::connectionFailed('memcached', $e->getMessage());
    }
}
