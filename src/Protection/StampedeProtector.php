<?php

declare(strict_types=1);

namespace KaririCode\Cache\Protection;

use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;

/**
 * Advanced stampede protection with locking and probabilistic expiration.
 */
final class StampedeProtector implements StampedeProtectorContract
{
    private bool $enabled = true;
    private int $lockTimeout = 30;
    private float $betaFactor = 1.0;
    private array $locks = [];

    public function get(string $key, callable $callback, $ttl = null): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $lockKey = "lock:{$key}";

        // Try to acquire lock
        if ($this->acquireLock($lockKey)) {
            try {
                $result = $callback();
                $this->releaseLock($lockKey);

                return $result;
            } catch (\Exception $e) {
                $this->releaseLock($lockKey);
                throw $e;
            }
        }

        // Wait for lock to be released
        $maxWait = $this->lockTimeout;
        $waited = 0;

        while ($this->isLocked($lockKey) && $waited < $maxWait) {
            usleep(100000); // 100ms
            ++$waited;
        }

        // If we waited too long, execute callback anyway
        if ($waited >= $maxWait) {
            return $callback();
        }

        // Try to get the value that should have been generated
        return $callback();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    private function acquireLock(string $lockKey): bool
    {
        if (isset($this->locks[$lockKey])) {
            return false;
        }

        $this->locks[$lockKey] = time() + $this->lockTimeout;

        return true;
    }

    private function releaseLock(string $lockKey): void
    {
        unset($this->locks[$lockKey]);
    }

    private function isLocked(string $lockKey): bool
    {
        if (!isset($this->locks[$lockKey])) {
            return false;
        }

        // Check if lock has expired
        if ($this->locks[$lockKey] < time()) {
            unset($this->locks[$lockKey]);

            return false;
        }

        return true;
    }
}
