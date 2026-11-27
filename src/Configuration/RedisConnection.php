<?php

declare(strict_types=1);

namespace KaririCode\Cache\Configuration;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\ConnectionException;

/**
 * Settings of one Redis server and the only place that opens a client
 * with them.
 *
 * Groups what used to be six scalar parameters of RedisAdapter (Spec V4
 * §13: at most four parameters per method). The password is private and
 * only open() reads it; #[\SensitiveParameter] keeps it out of stack
 * traces. Options are validated at construction.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Redis settings stay at the edge.
 * Spec V4: configuration value (final readonly class) with one behaviour.
 *
 * Preconditions: non-empty host; port 0..65535 (0 for Unix sockets); database >= 0; finite timeouts >= 0.
 * Thread-safety: immutable; every open() returns a new client.
 *
 * Side effects: open() connects, authenticates and selects the database.
 *
 * Example:
 *     $connection = new RedisConnection(host: 'redis', password: getenv('REDIS_PASSWORD') ?: null);
 *
 * References: ADR-013; SPEC-001 §7.
 *
 * @see https://github.com/phpredis/phpredis#connect-open
 */
final readonly class RedisConnection
{
    /**
     * @param float $timeout     Connect timeout in seconds; 0 = PHP default.
     * @param float $readTimeout Read timeout in seconds; 0 = PHP default.
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public int $database = 0,
        #[\SensitiveParameter]
        private ?string $password = null,
        public float $timeout = 2.0,
        public float $readTimeout = 2.0,
    ) {
        if ('' === $host) {
            throw ConfigurationException::invalidOption(option: 'host', constraint: 'must not be empty');
        }

        if ($port < 0 || $port > 65535) {
            throw ConfigurationException::invalidOption(option: 'port', constraint: 'must be between 0 and 65535');
        }

        if ($database < 0) {
            throw ConfigurationException::invalidOption(option: 'database', constraint: 'must be >= 0');
        }

        foreach (['timeout' => $timeout, 'readTimeout' => $readTimeout] as $option => $seconds) {
            if (!is_finite($seconds) || $seconds < 0.0) {
                throw ConfigurationException::invalidOption(option: $option, constraint: 'must be a finite number >= 0');
            }
        }
    }

    /**
     * Connect, authenticate, select the database and enable SCAN_RETRY.
     *
     * @throws ConfigurationException 8641 without ext-redis; ConnectionException 8610 when the
     *                             server cannot be reached or rejects us
     */
    public function open(): \Redis
    {
        if (!extension_loaded('redis')) {
            throw ConfigurationException::extensionMissing('redis');
        }

        $redis = new \Redis();

        try {
            $redis->connect(host: $this->host, port: $this->port, timeout: $this->timeout, persistent_id: null, retry_interval: 0, read_timeout: $this->readTimeout);

            // phpredis answers some refusals with false instead of throwing:
            // a rejected SELECT would otherwise leave the connection on database 0.
            if (null !== $this->password && true !== $redis->auth($this->password)) {
                throw new \RedisException('AUTH was not accepted');
            }

            if (0 !== $this->database && true !== $redis->select($this->database)) {
                throw new \RedisException((string) $redis->getLastError());
            }

            // Without SCAN_RETRY, SCAN may return an empty page before the end
            // of the iteration; 4.0 stopped there and flushed nothing.
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        } catch (\RedisException $e) {
            throw ConnectionException::connectionFailed('redis', $e);
        }

        return $redis;
    }
}
