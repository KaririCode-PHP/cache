<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Decides whether a caller should recompute an entry before (or at) its
 * logical expiry.
 *
 * Stateless strategies (XFetch) decide from time, expiry and recompute
 * cost alone and therefore work across processes. Keyed strategies
 * (LockingPrevention) coordinate by cache key inside one process.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): independent of backend and value.
 * Spec V4: contract (strategy).
 *
 * Preconditions: times are Unix seconds; delta >= 0.
 * Thread-safety: XFetch is stateless; LockingPrevention keeps a per-process lock table.
 *
 * Side effects: none outside the strategy.
 *
 * Example:
 *     $cache = new Cache($adapter, stampedePrevention: new LockingPrevention(lockDuration: 5.0));
 *
 * References: ADR-002; ADR-008.
 *
 * @see https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
 */
interface StampedePrevention
{
    /**
     * @param string $key         Cache key (used by keyed strategies).
     * @param float  $currentTime Current timestamp (microtime(true)).
     * @param float  $expiryTime  Logical expiry timestamp.
     * @param float  $delta       Measured recompute cost in seconds.
     */
    public function shouldRecompute(string $key, float $currentTime, float $expiryTime, float $delta): bool;

    public function name(): string;
}
