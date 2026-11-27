<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\EvictionResult;

/**
 * Least Frequently Used eviction with O(1) frequency buckets.
 *
 * Each key has an access count; keys with the same count live in one
 * insertion-ordered bucket, so ties are broken by age. The minimum
 * frequency is recomputed from the bucket keys whenever the tracked minimum
 * no longer exists — 4.0 scanned upward only to count(keys) and reported
 * "nothing to evict" after the least-used key was removed.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): tracks keys, never values.
 * Spec V4: Entity (controlled mutation behind methods).
 *
 * Preconditions: capacity >= 0.
 * Thread-safety: mutable; one instance per Cache.
 *
 * Side effects: in-memory bookkeeping only.
 *
 * Example:
 *     $cache = new Cache($adapter, new LfuPolicy(capacity: 1_000));
 *
 * References: ADR-001; SPEC-001 §10.
 *
 * @see http://dhruvbird.com/lfu.pdf
 */
final class LfuPolicy implements EvictionPolicy
{
    /** @var array<array-key, int> key => access count */
    private array $frequencies = [];

    /** @var array<int, array<array-key, true>> count => insertion-ordered key set */
    private array $buckets = [];

    private int $minFrequency = 0;

    /**
     * @param int $capacity Entries tracked before evict() is needed; 0 = unbounded.
     */
    public function __construct(
        public readonly int $capacity = 10_000,
    ) {
        if ($capacity < 0) {
            throw ConfigurationException::invalidOption(option: 'capacity', constraint: 'must be >= 0 (0 = unbounded)');
        }
    }

    // ── EvictionPolicy ───────────────────────────────────────────────────

    #[\Override]
    public function recordAccess(string $key): void
    {
        if (!isset($this->frequencies[$key])) {
            return;
        }

        $old = $this->frequencies[$key];
        $this->detach($key, $old);
        $this->attach($key, $old + 1);

        if ($old === $this->minFrequency && !isset($this->buckets[$old])) {
            $this->minFrequency = $old + 1;
        }
    }

    #[\Override]
    public function recordInsertion(string $key): void
    {
        if (isset($this->frequencies[$key])) {
            $this->recordAccess($key);

            return;
        }

        $this->attach($key, 1);
        $this->minFrequency = 1;
    }

    #[\Override]
    public function recordRemoval(string $key): void
    {
        if (!isset($this->frequencies[$key])) {
            return;
        }

        $this->detach($key, $this->frequencies[$key]);
        unset($this->frequencies[$key]);
    }

    #[\Override]
    public function evict(): EvictionResult
    {
        if ([] === $this->buckets) {
            return EvictionResult::empty();
        }

        if (!isset($this->buckets[$this->minFrequency])) {
            $this->minFrequency = min(array_keys($this->buckets));
        }

        // Numeric-string keys come back from PHP arrays as int.
        $key = (string) array_key_first($this->buckets[$this->minFrequency]);
        $this->recordRemoval($key);

        return EvictionResult::evicted($key);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->frequencies);
    }

    #[\Override]
    public function clear(): void
    {
        $this->frequencies = [];
        $this->buckets = [];
        $this->minFrequency = 0;
    }

    #[\Override]
    public function name(): string
    {
        return 'lfu';
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function attach(string $key, int $frequency): void
    {
        $this->frequencies[$key] = $frequency;
        $this->buckets[$frequency][$key] = true;
    }

    private function detach(string $key, int $frequency): void
    {
        unset($this->buckets[$frequency][$key]);

        if (isset($this->buckets[$frequency]) && [] === $this->buckets[$frequency]) {
            unset($this->buckets[$frequency]);
        }
    }
}
