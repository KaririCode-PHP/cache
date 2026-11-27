<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction\Node;

/**
 * Doubly linked list node of LruPolicy (key and neighbour links).
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): holds a key, never the cached value.
 * Spec V4: internal Entity; links are mutated by LruPolicy only.
 *
 * Preconditions: owned by one LruPolicy.
 * Thread-safety: mutable; never shared.
 *
 * Side effects: none.
 *
 * Example:
 *     // created by LruPolicy::recordInsertion(); not used directly
 *
 * References: SPEC-001 §10.
 *
 * @see https://en.wikipedia.org/wiki/Doubly_linked_list
 */
final class LruNode
{
    public ?self $prev = null;
    public ?self $next = null;

    public function __construct(
        public readonly string $key,
    ) {}
}
