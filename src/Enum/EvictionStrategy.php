<?php

declare(strict_types=1);

namespace KaririCode\Cache\Enum;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Eviction\FifoPolicy;
use KaririCode\Cache\Eviction\LfuPolicy;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Eviction\NullEvictionPolicy;
use KaririCode\Cache\Eviction\SievePolicy;

/**
 * Configuration names of the eviction policies; createPolicy() builds a
 * new, empty policy (it replaced EvictionPolicyFactory in 4.1.0, a class
 * that only wrapped `new`, banned by Spec V4 §18). SIEVE is the default of
 * Cache.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): policy chosen by configuration.
 * Spec V4: pure enum (errata E-003: no backing value is needed).
 *
 * Preconditions: createPolicy() takes capacity >= 0.
 * Thread-safety: immutable.
 *
 * Side effects: none.
 *
 * Example:
 *     $policy = EvictionStrategy::Lru->createPolicy(capacity: 1_000);
 *
 * References: ADR-001.
 *
 * @see https://www.usenix.org/conference/nsdi24/presentation/zhang-yazhuo
 */
enum EvictionStrategy
{
    /** SIEVE (NSDI 2024): visited bit plus a moving hand, no list update on hits. */
    case Sieve;

    /** Least Recently Used: move-to-head on every hit. */
    case Lru;

    /** Least Frequently Used: O(1) frequency buckets, ties broken by age. */
    case Lfu;

    /** First In, First Out: insertion order only. */
    case Fifo;

    /** No application-level eviction; the backend's limits still apply. */
    case None;

    /**
     * A new, empty policy of this strategy; None ignores $capacity.
     */
    public function createPolicy(int $capacity = 10_000): EvictionPolicy
    {
        return match ($this) {
            self::Sieve => new SievePolicy($capacity),
            self::Lru => new LruPolicy($capacity),
            self::Lfu => new LfuPolicy($capacity),
            self::Fifo => new FifoPolicy($capacity),
            self::None => new NullEvictionPolicy(),
        };
    }
}
