<?php

declare(strict_types=1);

namespace KaririCode\Cache\Protection;

use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Contract\ImmutableValue;

/**
 * Implements stampede protection using a non-blocking locking mechanism.
 *
 * NOTE: This implementation uses an in-memory lock, which is only effective
 * for preventing concurrency issues within a single PHP process (e.g., async tasks
 * in a command-line script). It will NOT prevent a stampede across multiple
 * web requests in a typical PHP-FPM environment. For inter-process protection,
 * a shared storage mechanism (like Redis, Memcached, or file-based locks) is required.
 */
final class StampedeProtector implements StampedeProtectorContract, ImmutableValue
{
    /**
     * In-memory locks. Key is the lock name, value is the expiration timestamp.
     *
     * @var array<string, int>
     */
    private static array $locks = [];

    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $lockTimeout = 10, // seconds
        private readonly int $lockRetryDelay = 100 // milliseconds
    ) {
    }

    public function get(string $key, callable $callback, $ttl = null): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $lockKey = 'stampede_lock::' . $key;

        // Attempt to acquire the lock.
        if ($this->acquireLock($lockKey)) {
            try {
                // If successful, this process generates the value.
                return $callback();
            } finally {
                // Always release the lock.
                $this->releaseLock($lockKey);
            }
        }

        // If lock is not acquired, wait for a short period.
        usleep($this->lockRetryDelay * 1000); // Use the configured delay

        // After waiting, we don't try to get the value again from cache.
        // We let the original getWithCallback() handle that.
        // This protector's only job is to serialize the execution of the callback.
        // In a more advanced implementation, this part could return the now-cached value.
        // For simplicity (KISS), we re-execute the callback if the wait fails.
        return $callback();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns a new instance with stampede protection enabled.
     */
    public function enable(): self
    {
        return new self(true, $this->lockTimeout, $this->lockRetryDelay);
    }

    /**
     * Returns a new instance with stampede protection disabled.
     */
    public function disable(): self
    {
        return new self(false, $this->lockTimeout, $this->lockRetryDelay);
    }

    private function acquireLock(string $lockKey): bool
    {
        $this->cleanupExpiredLock($lockKey);

        if (isset(self::$locks[$lockKey])) {
            return false; // Lock is held by another process/thread in this instance.
        }

        self::$locks[$lockKey] = time() + $this->lockTimeout;

        return true;
    }

    private function releaseLock(string $lockKey): void
    {
        unset(self::$locks[$lockKey]);
    }

    private function cleanupExpiredLock(string $lockKey): void
    {
        if (isset(self::$locks[$lockKey]) && self::$locks[$lockKey] < time()) {
            unset(self::$locks[$lockKey]);
        }
    }
}
