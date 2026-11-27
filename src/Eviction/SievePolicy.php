<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Eviction\Node\SieveNode;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\EvictionResult;

/**
 * SIEVE eviction (Zhang, Yang, Yue, Vigfusson, Rashmi — "SIEVE is Simpler
 * than LRU: an Efficient Turn-Key Eviction Algorithm for Web Caches",
 * USENIX NSDI 2024).
 *
 * One FIFO queue (newest at the head), one visited bit per entry and a
 * "hand" that moves from the tail towards the head:
 *   - hit:       set the visited bit (no list update);
 *   - insert:    add at the head with the bit cleared;
 *   - eviction:  at the hand, a visited entry has its bit cleared and
 *                the hand moves on; the first unvisited entry is evicted
 *                and the hand stays at its newer neighbour, wrapping from
 *                the head back to the tail.
 * Amortized O(1) per operation. The paper reports up to 63.2% lower miss
 * ratio than ARC across 1559 traces. Unvisited entries go first, which
 * limits the damage of one-time keys, but there is no explicit scan
 * protection (as in ARC or 2Q), and in benchmark/ LRU adapted faster than
 * SIEVE when popularity shifted abruptly.
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
 *     $cache = new Cache($adapter, new SievePolicy(capacity: 10_000));
 *
 * References: ADR-001; SPEC-001 §10.
 *
 * @see https://www.usenix.org/conference/nsdi24/presentation/zhang-yazhuo
 */
final class SievePolicy implements EvictionPolicy
{
    /** @var array<string, SieveNode> */
    private array $nodes = [];

    private ?SieveNode $head = null;
    private ?SieveNode $tail = null;
    private ?SieveNode $hand = null;

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
        if (isset($this->nodes[$key])) {
            // Lazy promotion: just set visited bit, no list reordering
            $this->nodes[$key]->visited = true;
        }
    }

    #[\Override]
    public function recordInsertion(string $key): void
    {
        // Idempotent re-insert: remove the existing node via recordRemoval()
        // so the hand pointer is safely advanced before detaching the node.
        // Calling removeNode() directly here would strand the hand on a
        // detached node and corrupt head/tail on the next eviction.
        if (isset($this->nodes[$key])) {
            $this->recordRemoval($key);
        }

        // New entries always inserted at head with visited=false
        $node = new SieveNode($key);
        $this->insertAtHead($node);
        $this->nodes[$key] = $node;
    }

    #[\Override]
    public function recordRemoval(string $key): void
    {
        if (!isset($this->nodes[$key])) {
            return;
        }

        $node = $this->nodes[$key];

        // Advance hand if it points to the node being removed
        if ($this->hand === $node) {
            $this->hand = $node->prev ?? $this->tail;

            if ($this->hand === $node) {
                $this->hand = null;
            }
        }

        $this->removeNode($node);
        unset($this->nodes[$key]);
    }

    #[\Override]
    public function evict(): EvictionResult
    {
        if ([] === $this->nodes) {
            return EvictionResult::empty();
        }

        // Initialize hand at tail if not set
        $this->hand ??= $this->tail;

        // Scan: reset visited bits until we find an unvisited entry
        // Worst case: one full rotation of the list
        $scanned = 0;
        // n steps clear every bit in the worst case; step n + 1 then finds
        // the entry at the hand unvisited, as in the paper's loop.
        $limit = count($this->nodes) + 1;

        while ($scanned < $limit) {
            $current = $this->hand;

            if (null === $current) {
                $this->hand = $this->tail;
                $current = $this->hand;

                if (null === $current) {
                    return EvictionResult::empty();
                }
            }

            if ($current->visited) {
                // Reset visited bit, advance hand toward head
                $current->visited = false;
                $this->hand = $current->prev ?? $this->tail;
                ++$scanned;

                continue;
            }

            // Found unvisited entry — evict it
            $evictedKey = $current->key;
            $this->hand = $current->prev;
            $this->removeNode($current);
            unset($this->nodes[$evictedKey]);

            $this->hand ??= $this->tail;

            return EvictionResult::evicted($evictedKey);
        }

        return EvictionResult::empty();
    }

    #[\Override]
    public function count(): int
    {
        return count($this->nodes);
    }

    #[\Override]
    public function clear(): void
    {
        $this->nodes = [];
        $this->head = null;
        $this->tail = null;
        $this->hand = null;
    }

    #[\Override]
    public function name(): string
    {
        return 'sieve';
    }

    // ── Doubly-Linked List Operations ────────────────────────────────────
    private function insertAtHead(SieveNode $node): void
    {
        $node->next = $this->head;
        $node->prev = null;

        if (null !== $this->head) {
            $this->head->prev = $node;
        }

        $this->head = $node;
        $this->tail ??= $node;
    }

    private function removeNode(SieveNode $node): void
    {
        if (null !== $node->prev) {
            $node->prev->next = $node->next;
        } else {
            $this->head = $node->next;
        }

        if (null !== $node->next) {
            $node->next->prev = $node->prev;
        } else {
            $this->tail = $node->prev;
        }

        $node->prev = null;
        $node->next = null;
    }
}
