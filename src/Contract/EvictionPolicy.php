<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\ValueObject\EvictionResult;

/**
 * Application-level capacity policy used by Cache: how many entries may be
 * tracked (capacity) and which one leaves when the limit is reached.
 *
 * The policy tracks keys in process memory. With a process-local adapter
 * (ArrayAdapter) or a long-running worker it bounds the cache; with a
 * shared backend under PHP-FPM each request sees only the keys it wrote,
 * so capacity must be enforced by the backend itself (Redis maxmemory
 * policy, APCu shm size). See ADR-010.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): policies track keys only, never values or backends.
 * Spec V4: contract (strategy); implementations are Entities.
 *
 * Preconditions: capacity >= 0; only Cache reports keys to it.
 * Thread-safety: mutable; one instance per Cache.
 *
 * Side effects: in-memory bookkeeping only.
 *
 * Example:
 *     $cache = new Cache($adapter, new LruPolicy(capacity: 500));
 *
 * References: ADR-001; ADR-010.
 *
 * @see https://www.usenix.org/conference/nsdi24/presentation/zhang-yazhuo
 */
interface EvictionPolicy
{
    /**
     * Entries tracked before Cache asks for an eviction; 0 = unbounded.
     */
    public int $capacity { get; }

    public function recordAccess(string $key): void;

    public function recordInsertion(string $key): void;

    public function recordRemoval(string $key): void;

    public function evict(): EvictionResult;

    public function count(): int;

    public function clear(): void;

    public function name(): string;
}
