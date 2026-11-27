<?php

declare(strict_types=1);

namespace KaririCode\Cache\Adapter;

use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\AdapterStats;
use Psr\Clock\ClockInterface;

/**
 * In-process memory adapter for tests, CLI scripts and the fastest layer
 * of a LayeredCache.
 *
 * Entries live in one insertion-ordered map. When maxItems is reached the
 * oldest entry is dropped (FIFO; deleting and writing a key again makes it
 * the newest). Values are held by reference: an object mutated after
 * write() changes the cached value, unlike serializing adapters.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): same CacheAdapter port as Redis.
 * Spec V4: Entity (state mutated behind methods); maxItems validated.
 *
 * Preconditions: maxItems >= 0 (0 = unbounded).
 * Thread-safety: process memory; confine an instance to one worker.
 *
 * Side effects: none outside the process; an overflow drops the oldest entry.
 *
 * Example:
 *     $cache = new Cache(new ArrayAdapter(maxItems: 1_000));
 *
 * References: ADR-010; SPEC-001 §7.
 *
 * @see https://www.php.net/manual/en/language.types.array.php
 */
final class ArrayAdapter implements CacheAdapter
{
    /**
     * Insertion-ordered; PHP turns numeric-string keys into int keys.
     *
     * @var array<array-key, array{value: mixed, expiresAt: float|null}>
     */
    private array $storage = [];

    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;
    private int $deletes = 0;

    /**
     * @param int $maxItems Hard limit before FIFO overflow; 0 = unbounded.
     */
    public function __construct(
        private readonly int $maxItems = 10_000,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if ($maxItems < 0) {
            throw ConfigurationException::invalidOption(option: 'maxItems', constraint: 'must be >= 0 (0 = unbounded)');
        }
    }

    // ── CacheAdapter ─────────────────────────────────────────────────────

    #[\Override]
    public function read(string $key): mixed
    {
        $entry = $this->storage[$key] ?? null;

        if (null === $entry || $this->hasExpired($entry['expiresAt'])) {
            if (null !== $entry) {
                $this->removeEntry($key);
            }

            ++$this->misses;

            return null;
        }

        ++$this->hits;

        return $entry['value'];
    }

    #[\Override]
    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (null !== $ttl && $ttl <= 0) {
            $this->remove($key);

            return true;
        }

        if (!isset($this->storage[$key]) && $this->maxItems > 0 && count($this->storage) >= $this->maxItems) {
            $this->dropOldest();
        }

        $this->storage[$key] = [
            'value' => $value,
            'expiresAt' => null === $ttl ? null : $this->now() + (float) $ttl,
        ];
        ++$this->writes;

        return true;
    }

    #[\Override]
    public function remove(string $key): bool
    {
        if (isset($this->storage[$key])) {
            $this->removeEntry($key);
        }

        return true;
    }

    #[\Override]
    public function exists(string $key): bool
    {
        $entry = $this->storage[$key] ?? null;

        if (null === $entry) {
            return false;
        }

        if ($this->hasExpired($entry['expiresAt'])) {
            $this->removeEntry($key);

            return false;
        }

        return true;
    }

    #[\Override]
    public function flush(): bool
    {
        $this->storage = [];

        return true;
    }

    #[\Override]
    public function readMultiple(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->read($key);
        }

        return $results;
    }

    #[\Override]
    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->write(key: (string) $key, value: $value, ttl: $ttl);
        }

        return true;
    }

    #[\Override]
    public function removeMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->remove($key);
        }

        return true;
    }

    #[\Override]
    public function stats(): AdapterStats
    {
        return new AdapterStats(
            hits: $this->hits,
            misses: $this->misses,
            writes: $this->writes,
            deletes: $this->deletes,
            itemCount: count($this->storage),
            memoryBytes: 0,
            adapterName: $this->name(),
        );
    }

    #[\Override]
    public function name(): string
    {
        return 'array';
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Remove every expired entry; returns how many were removed.
     */
    public function prune(): int
    {
        $removed = 0;

        foreach ($this->storage as $key => $entry) {
            if ($this->hasExpired($entry['expiresAt'])) {
                $this->removeEntry((string) $key);
                ++$removed;
            }
        }

        return $removed;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function hasExpired(?float $expiresAt): bool
    {
        return null !== $expiresAt && $this->now() > $expiresAt;
    }

    private function removeEntry(string $key): void
    {
        unset($this->storage[$key]);
        ++$this->deletes;
    }

    private function dropOldest(): void
    {
        $oldest = array_key_first($this->storage);

        if (null !== $oldest) {
            $this->removeEntry((string) $oldest);
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
