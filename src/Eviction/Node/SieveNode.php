<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction\Node;

/**
 * Queue node of SievePolicy: key, visited bit and neighbour links.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): holds a key, never the cached value.
 * Spec V4: internal Entity; mutated by SievePolicy only.
 *
 * Preconditions: owned by one SievePolicy.
 * Thread-safety: mutable; never shared.
 *
 * Side effects: none.
 *
 * Example:
 *     // created by SievePolicy::recordInsertion(); not used directly
 *
 * References: ADR-001.
 *
 * @see https://www.usenix.org/conference/nsdi24/presentation/zhang-yazhuo
 */
final class SieveNode
{
    public bool $visited = false;
    public ?self $prev = null;
    public ?self $next = null;

    public function __construct(
        public readonly string $key,
    ) {}
}
