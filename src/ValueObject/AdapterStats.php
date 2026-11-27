<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * Snapshot of an adapter's counters (hits, misses, writes, deletes) and of
 * the backend size, for metric exporters.
 *
 * ARFA 1.3 P5 (Continuous Observability): counters for metric exporters.
 * Spec V4: Value Object (final readonly class); derived values are query
 * methods (E-040).
 *
 * Preconditions: counters are per adapter instance and process; itemCount
 * and memoryBytes describe the backend (RedisAdapter reports DBSIZE).
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $ratio = $adapter->stats()->hitRatio();
 *
 * References: ADR-007; SPEC-001 §7.
 *
 * @see https://opentelemetry.io/docs/specs/otel/metrics/data-model/
 */
final readonly class AdapterStats
{
    public function __construct(
        public int $hits = 0,
        public int $misses = 0,
        public int $writes = 0,
        public int $deletes = 0,
        public int $itemCount = 0,
        public int $memoryBytes = 0,
        public string $adapterName = '',
    ) {}

    public function totalRequests(): int
    {
        return $this->hits + $this->misses;
    }

    /**
     * Hits over reads, 0.0 before the first read.
     */
    public function hitRatio(): float
    {
        $total = $this->totalRequests();

        return 0 === $total ? 0.0 : $this->hits / $total;
    }
}
