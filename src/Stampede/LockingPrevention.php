<?php

declare(strict_types=1);

namespace KaririCode\Cache\Stampede;

use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Per-key, process-local lock: after the logical expiry, the first caller
 * of a key recomputes and later callers keep the stored value until the
 * lock window ends.
 *
 * Effective with rememberStale(), where the value outlives its logical
 * expiry. With remember() the entry is physically gone at expiry, so the
 * miss path recomputes regardless of the lock. The lock lives in process
 * memory: it coordinates one long-running worker, not a PHP-FPM pool.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): backend-independent.
 * Spec V4: Entity (lock table mutated behind methods); options validated.
 *
 * Preconditions: lockDuration > 0.
 * Thread-safety: per-process lock table; one instance per worker.
 *
 * Side effects: in-memory locks only.
 *
 * Example:
 *     $cache = new Cache($adapter, stampedePrevention: new LockingPrevention(lockDuration: 5.0));
 *
 * References: ADR-008.
 *
 * @see https://martin.kleppmann.com/2016/02/08/how-to-do-distributed-locking.html
 */
final class LockingPrevention implements StampedePrevention
{
    /** @var array<string, float> key => lock expiry timestamp */
    private array $locks = [];

    /**
     * @param float $lockDuration Seconds a lock is held before another
     *                            caller of the same key may recompute.
     */
    public function __construct(
        private readonly float $lockDuration = 10.0,
    ) {
        if (!is_finite($lockDuration) || $lockDuration <= 0.0) {
            throw ConfigurationException::invalidOption(option: 'lockDuration', constraint: 'must be a finite number > 0');
        }
    }

    #[\Override]
    public function shouldRecompute(string $key, float $currentTime, float $expiryTime, float $delta): bool
    {
        if ($expiryTime > 0.0 && $currentTime < $expiryTime) {
            return false;
        }

        $this->pruneExpiredLocks($currentTime);

        return $this->tryLock($key, $currentTime);
    }

    /**
     * Acquire the lock of $key; false when another caller holds it.
     */
    public function tryLock(string $key, float $currentTime): bool
    {
        if (isset($this->locks[$key]) && $currentTime < $this->locks[$key]) {
            return false;
        }

        $this->locks[$key] = $currentTime + $this->lockDuration;

        return true;
    }

    public function releaseLock(string $key): void
    {
        unset($this->locks[$key]);
    }

    #[\Override]
    public function name(): string
    {
        return 'locking';
    }

    private function pruneExpiredLocks(float $currentTime): void
    {
        foreach ($this->locks as $key => $expiry) {
            if ($currentTime >= $expiry) {
                unset($this->locks[$key]);
            }
        }
    }
}
