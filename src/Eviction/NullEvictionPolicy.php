<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\ValueObject\EvictionResult;

/**
 * Policy that never evicts: no application-level capacity limit. The
 * backend's own limits (Redis maxmemory, APCu shm size) still apply.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Null Object behind the EvictionPolicy port.
 * Spec V4: stateless service.
 *
 * Preconditions: none.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $cache = new Cache($redisAdapter, new NullEvictionPolicy()); // Redis maxmemory bounds the data
 *
 * References: ADR-010.
 *
 * @see https://en.wikipedia.org/wiki/Null_object_pattern
 */
final class NullEvictionPolicy implements EvictionPolicy
{
    /** Always 0: this policy never limits the number of entries. */
    public int $capacity {
        get => 0;
    }

    /** @var array<string, true> */
    private array $keys = [];

    #[\Override]
    public function recordAccess(string $key): void {}

    #[\Override]
    public function recordInsertion(string $key): void
    {
        $this->keys[$key] = true;
    }

    #[\Override]
    public function recordRemoval(string $key): void
    {
        unset($this->keys[$key]);
    }

    #[\Override]
    public function evict(): EvictionResult
    {
        return EvictionResult::empty();
    }

    #[\Override]
    public function count(): int
    {
        return count($this->keys);
    }

    #[\Override]
    public function clear(): void
    {
        $this->keys = [];
    }

    #[\Override]
    public function name(): string
    {
        return 'none';
    }
}
