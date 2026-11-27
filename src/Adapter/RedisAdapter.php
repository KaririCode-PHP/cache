<?php

declare(strict_types=1);

namespace KaririCode\Cache\Adapter;

use KaririCode\Cache\Configuration\RedisConnection;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serialization\PayloadCodec;
use KaririCode\Cache\ValueObject\AdapterStats;

/**
 * Redis adapter (ext-redis): distributed, persistent cache shared by every
 * worker and server. Connection settings live in RedisConnection.
 *
 * Values go through Serializer then Compressor. Keys are prefixed by this
 * adapter; flush() with a prefix deletes only matching keys (SCAN with
 * SCAN_RETRY, glob metacharacters of the prefix escaped) and, without a
 * prefix, flushes the selected database. \RedisException never leaks:
 * connection problems raise ConnectionException, operation failures raise
 * CacheException. An entry that cannot be decoded is deleted and reported
 * as a miss.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Redis types stay inside the adapter.
 * Spec V4: Entity (connection and counters).
 *
 * Preconditions: a reachable server (RedisConnection); the prefix isolates this adapter's keys.
 * Thread-safety: one connection per instance; do not share an instance across coroutines.
 *
 * Side effects: network I/O; flush() runs SCAN + DEL on the prefix, FLUSHDB without one.
 *
 * Example:
 *     $adapter = new RedisAdapter(new RedisConnection(host: 'redis'), prefix: 'app:');
 *
 * References: ADR-005; ADR-010; SPEC-001 §7.
 *
 * @see https://redis.io/docs/latest/commands/scan/
 */
final class RedisAdapter implements CacheAdapter
{
    private \Redis $redis;

    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;
    private int $deletes = 0;

    public function __construct(
        RedisConnection $connection = new RedisConnection(),
        private readonly string $prefix = '',
        private readonly PayloadCodec $codec = new PayloadCodec(),
    ) {
        $this->redis = $connection->open();
    }

    // ── CacheAdapter ─────────────────────────────────────────────────────

    #[\Override]
    public function read(string $key): mixed
    {
        try {
            $data = $this->redis->get($this->prefix . $key);
        } catch (\RedisException $e) {
            throw BackendException::readFailed($e);
        }

        if (!is_string($data)) {
            ++$this->misses;

            return null;
        }

        return $this->decodeOrForget($key, $data);
    }

    #[\Override]
    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (null !== $ttl && $ttl <= 0) {
            return $this->remove($key);
        }

        $payload = $this->encode($value);

        try {
            $result = null === $ttl
                ? $this->redis->set($this->prefix . $key, $payload)
                : $this->redis->setex(key: $this->prefix . $key, expire: $ttl, value: $payload);
        } catch (\RedisException $e) {
            throw BackendException::writeFailed($e);
        }

        ++$this->writes;

        return true === $result;
    }

    #[\Override]
    public function remove(string $key): bool
    {
        try {
            $deleted = $this->redis->del($this->prefix . $key);
        } catch (\RedisException $e) {
            throw BackendException::deleteFailed($e);
        }

        if (is_int($deleted)) {
            $this->deletes += $deleted;

            return true;
        }

        return false;
    }

    #[\Override]
    public function exists(string $key): bool
    {
        try {
            $count = $this->redis->exists($this->prefix . $key);
        } catch (\RedisException $e) {
            throw BackendException::readFailed($e);
        }

        return is_int($count) ? $count > 0 : true === $count;
    }

    #[\Override]
    public function flush(): bool
    {
        try {
            if ('' === $this->prefix) {
                return true === $this->redis->flushDB();
            }

            $pattern = addcslashes($this->prefix, '*?[]\\') . '*';
            $iterator = null;

            while (false !== ($keys = $this->redis->scan(iterator: $iterator, pattern: $pattern, count: 1000))) {
                if ([] !== $keys) {
                    $deleted = $this->redis->del($keys);
                    $this->deletes += is_int($deleted) ? $deleted : 0;
                }
            }

            return true;
        } catch (\RedisException $e) {
            throw BackendException::flushFailed($e);
        }
    }

    #[\Override]
    public function readMultiple(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        try {
            $values = $this->redis->mget(array_map(fn(string $key): string => $this->prefix . $key, $keys));
        } catch (\RedisException $e) {
            throw BackendException::readFailed($e);
        }

        $values = is_array($values) ? array_values($values) : [];
        $results = [];

        foreach ($keys as $i => $key) {
            $data = $values[$i] ?? false;

            if (is_string($data)) {
                $results[$key] = $this->decodeOrForget($key, $data);
            } else {
                $results[$key] = null;
                ++$this->misses;
            }
        }

        return $results;
    }

    #[\Override]
    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        if ([] === $values) {
            return true;
        }

        if (null !== $ttl && $ttl <= 0) {
            return $this->removeMultiple(array_map(strval(...), array_keys($values)));
        }

        try {
            $pipeline = $this->redis->pipeline();

            foreach ($values as $key => $value) {
                $payload = $this->encode($value);
                null === $ttl
                    ? $pipeline->set($this->prefix . $key, $payload)
                    : $pipeline->setex(key: $this->prefix . $key, expire: $ttl, value: $payload);
            }

            $results = $pipeline->exec();
        } catch (\RedisException $e) {
            throw BackendException::writeFailed($e);
        }

        $this->writes += count($values);

        return is_array($results) && !in_array(false, $results, true);
    }

    #[\Override]
    public function removeMultiple(array $keys): bool
    {
        if ([] === $keys) {
            return true;
        }

        try {
            $deleted = $this->redis->del(array_map(fn(string $key): string => $this->prefix . $key, $keys));
        } catch (\RedisException $e) {
            throw BackendException::deleteFailed($e);
        }

        if (is_int($deleted)) {
            $this->deletes += $deleted;

            return true;
        }

        return false;
    }

    /**
     * itemCount is DBSIZE: every key of the selected database, not only the
     * keys under this adapter's prefix.
     */
    #[\Override]
    public function stats(): AdapterStats
    {
        try {
            $size = $this->redis->dbSize();
            $memory = $this->redis->info('memory');
        } catch (\RedisException $e) {
            throw BackendException::readFailed($e);
        }

        $used = is_array($memory) ? ($memory['used_memory'] ?? 0) : 0;

        return new AdapterStats(
            hits: $this->hits,
            misses: $this->misses,
            writes: $this->writes,
            deletes: $this->deletes,
            itemCount: is_int($size) ? $size : 0,
            memoryBytes: is_numeric($used) ? (int) $used : 0,
            adapterName: $this->name(),
        );
    }

    #[\Override]
    public function name(): string
    {
        return 'redis';
    }

    #[\Override]
    public function isAvailable(): bool
    {
        try {
            $pong = $this->redis->ping();
        } catch (\RedisException) {
            return false;
        }

        // phpredis >= 5 returns true; older versions return "+PONG".
        return true === $pong || '+PONG' === $pong || 'PONG' === $pong;
    }

    /**
     * Raw connection for operations outside the cache contract (atomic
     * counters, Lua). Keys written here bypass the prefix and the
     * serializer.
     */
    public function connection(): \Redis
    {
        return $this->redis;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function encode(mixed $value): string
    {
        return $this->codec->encode($value);
    }

    private function decodeOrForget(string $key, string $data): mixed
    {
        try {
            $value = $this->codec->decode($data);
        } catch (CacheException) {
            $this->remove($key);
            ++$this->misses;

            return null;
        }

        ++$this->hits;

        return $value;
    }
}
