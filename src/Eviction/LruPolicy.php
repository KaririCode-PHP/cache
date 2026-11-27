<?php

declare(strict_types=1);

namespace KaririCode\Cache\Eviction;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Eviction\Node\LruNode;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\EvictionResult;

/**
 * Least Recently Used eviction: hash map plus doubly linked list, O(1)
 * per operation. Every hit moves the entry to the head of the list, the
 * per-read bookkeeping that SIEVE avoids.
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
 *     $cache = new Cache($adapter, new LruPolicy(capacity: 1_000));
 *
 * References: ADR-001; SPEC-001 §10.
 *
 * @see https://en.wikipedia.org/wiki/Cache_replacement_policies#Least_recently_used_(LRU)
 */
final class LruPolicy implements EvictionPolicy
{
    /** @var array<string, LruNode> */
    private array $nodes = [];

    private ?LruNode $head = null;
    private ?LruNode $tail = null;

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

    #[\Override]
    public function recordAccess(string $key): void
    {
        if (isset($this->nodes[$key])) {
            $this->moveToHead($this->nodes[$key]);
        }
    }

    #[\Override]
    public function recordInsertion(string $key): void
    {
        if (isset($this->nodes[$key])) {
            $this->moveToHead($this->nodes[$key]);

            return;
        }

        $node = new LruNode($key);
        $this->insertAtHead($node);
        $this->nodes[$key] = $node;
    }

    #[\Override]
    public function recordRemoval(string $key): void
    {
        if (isset($this->nodes[$key])) {
            $this->removeNode($this->nodes[$key]);
            unset($this->nodes[$key]);
        }
    }

    #[\Override]
    public function evict(): EvictionResult
    {
        if (null === $this->tail) {
            return EvictionResult::empty();
        }

        $evictedKey = $this->tail->key;
        $this->removeNode($this->tail);
        unset($this->nodes[$evictedKey]);

        return EvictionResult::evicted($evictedKey);
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
    }

    #[\Override]
    public function name(): string
    {
        return 'lru';
    }

    // ── Doubly-Linked List Operations ────────────────────────────────────
    private function moveToHead(LruNode $node): void
    {
        if ($node === $this->head) {
            return;
        }

        $this->removeNode($node);
        $this->insertAtHead($node);
    }

    private function insertAtHead(LruNode $node): void
    {
        $node->next = $this->head;
        $node->prev = null;

        if (null !== $this->head) {
            $this->head->prev = $node;
        }

        $this->head = $node;
        $this->tail ??= $node;
    }

    private function removeNode(LruNode $node): void
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
