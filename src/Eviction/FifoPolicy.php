<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\EvictionResult;

/**
 * First In, First Out eviction over an insertion-ordered hash map.
 *
 * PHP arrays keep insertion order, so the oldest key is array_key_first()
 * and removal is unset(): O(1) for every operation. A key that is removed
 * and inserted again moves to the tail. 4.0 used a lazily cleaned list
 * with array_shift() (O(n) per eviction) and evicted a re-inserted key by
 * its original position.
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
 *     $cache = new Cache($adapter, new FifoPolicy(capacity: 1_000));
 *
 * References: ADR-001; SPEC-001 §10.
 *
 * @see https://en.wikipedia.org/wiki/Cache_replacement_policies#First_in_first_out_(FIFO)
 */
final class FifoPolicy implements EvictionPolicy
{
    /** @var array<array-key, true> insertion-ordered key set (numeric keys become int) */
    private array $order = [];

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
        // FIFO ignores accesses by definition.
    }

    #[\Override]
    public function recordInsertion(string $key): void
    {
        $this->order[$key] ??= true;
    }

    #[\Override]
    public function recordRemoval(string $key): void
    {
        unset($this->order[$key]);
    }

    #[\Override]
    public function evict(): EvictionResult
    {
        $key = array_key_first($this->order);

        if (null === $key) {
            return EvictionResult::empty();
        }

        unset($this->order[$key]);

        return EvictionResult::evicted((string) $key);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->order);
    }

    #[\Override]
    public function clear(): void
    {
        $this->order = [];
    }

    #[\Override]
    public function name(): string
    {
        return 'fifo';
    }
}
