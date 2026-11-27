<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Observation hooks fired by Cache on every operation.
 *
 * Implement it to emit metrics, traces or logs (for example through
 * kariricode/observability). Hooks run synchronously on the hot path:
 * keep them cheap and never throw from them. NullEventListener is the
 * no-op default.
 *
 * ARFA 1.3 P5 (Continuous Observability): one hook per cache outcome.
 * Spec V4: contract (observer port).
 *
 * Preconditions: hooks are cheap and never throw; they run synchronously on every call.
 * Thread-safety: implementation-defined.
 *
 * Side effects: whatever the listener emits (metrics, logs, traces).
 *
 * Example:
 *     $cache = new Cache($adapter, eventListener: new MetricsListener());
 *
 * References: ADR-007.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/
 */
interface CacheEventListener
{
    public function onHit(string $key): void;

    public function onMiss(string $key): void;

    public function onWrite(string $key, ?int $ttl): void;

    public function onDelete(string $key): void;

    public function onEviction(string $key, string $policyName): void;

    /**
     * @param float $gapSeconds Seconds left before the logical expiry when
     *                          stampede prevention chose to recompute.
     */
    public function onStampedeRecompute(string $key, float $gapSeconds): void;
}
