<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\SilentLogger;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Provides a distributed locking mechanism using a PSR-6 compatible cache pool
 * that also supports atomic operations. It leverages the atomic 'add' operation
 * to ensure lock integrity across different processes.
 */
class LockService
{
    private const DEFAULT_LOCK_TTL = 10; // seconds
    private const DEFAULT_WAIT_TIMEOUT = 5.0; // seconds
    private const WAIT_INTERVAL_MICROSECONDS = 100000; // 100ms

    /** @var array<string, true> A list of locks acquired by this instance. */
    private array $acquiredLocks = [];

    /**
     * @param AtomicOperable&CacheItemPoolInterface $cache The cache pool, which must support atomic operations
     * @param Logger $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly AtomicOperable&CacheItemPoolInterface $cache,
        private readonly Logger $logger = new SilentLogger()
    ) {
    }

    /**
     * Ensures any acquired locks are released when the object is destroyed.
     * This is a safeguard against unterminated scripts leaving stale locks.
     */
    public function __destruct()
    {
        if (!empty($this->acquiredLocks)) {
            $this->releaseAll();
        }
    }

    /**
     * Acquires a lock for a given key, waiting for a specified duration if necessary.
     *
     * @param string $key the identifier for the lock
     * @param int $ttl the time-to-live for the lock itself, preventing permanent locks
     * @param float $maxWaitTime the maximum time in seconds to wait to acquire the lock
     *
     * @return bool true if the lock was acquired, false on timeout
     */
    public function acquire(string $key, int $ttl = self::DEFAULT_LOCK_TTL, float $maxWaitTime = self::DEFAULT_WAIT_TIMEOUT): bool
    {
        $lockKey = $this->getLockKey($key);
        $startTime = microtime(true);

        while ((microtime(true) - $startTime) < $maxWaitTime) {
            // The `add` operation is atomic, succeeding only if the key does not exist.
            if ($this->cache->add($lockKey, 1, $ttl)) {
                $this->acquiredLocks[$lockKey] = true;
                $this->logger->debug('Lock acquired successfully.', ['key' => $key, 'lock_key' => $lockKey]);

                return true;
            }

            usleep(self::WAIT_INTERVAL_MICROSECONDS);
        }

        $this->logger->warning('Lock acquisition timed out.', ['key' => $key, 'wait_time' => $maxWaitTime]);

        return false;
    }

    /**
     * Releases a previously acquired lock.
     *
     * @param string $key the identifier for the lock
     *
     * @return bool true if the lock was successfully released
     */
    public function release(string $key): bool
    {
        $lockKey = $this->getLockKey($key);

        if (!isset($this->acquiredLocks[$lockKey])) {
            $this->logger->warning('Attempted to release a lock that was not acquired by this instance.', ['key' => $key]);

            return false;
        }

        unset($this->acquiredLocks[$lockKey]);
        $success = $this->cache->deleteItem($lockKey);

        $this->logger->debug('Lock released.', ['key' => $key, 'lock_key' => $lockKey, 'success' => $success]);

        return $success;
    }

    /**
     * Executes a callback exclusively by wrapping it with lock acquisition and release.
     *
     * @param string $key the identifier for the lock
     * @param callable $callback the code to execute once the lock is acquired
     * @param int $ttl the TTL for the lock itself
     * @param float $maxWaitTime the maximum time to wait for the lock
     *
     * @throws CacheException if the lock cannot be acquired
     *
     * @return mixed the return value of the callback
     */
    public function withLock(string $key, callable $callback, int $ttl = self::DEFAULT_LOCK_TTL, float $maxWaitTime = self::DEFAULT_WAIT_TIMEOUT): mixed
    {
        if (!$this->acquire($key, $ttl, $maxWaitTime)) {
            throw CacheException::lockTimeout($key, (int) $maxWaitTime);
        }

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
    }

    /**
     * Releases all locks that were acquired by this specific LockService instance.
     */
    public function releaseAll(): void
    {
        $count = count($this->acquiredLocks);
        foreach (array_keys($this->acquiredLocks) as $lockKey) {
            $this->cache->deleteItem($lockKey);
        }
        $this->acquiredLocks = [];
        $this->logger->info('Released all held locks.', ['count' => $count]);
    }

    /**
     * Generates a standardized lock key from a resource key.
     */
    private function getLockKey(string $key): string
    {
        return 'lock:' . hash('sha256', $key);
    }
}
