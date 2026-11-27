<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\ValueObject\AdapterStats;

/**
 * Storage backend contract: raw I/O only, no policy.
 *
 * TTL semantics shared by every adapter:
 *   - null  → the entry does not expire;
 *   - > 0   → the entry expires after that many seconds;
 *   - <= 0  → the entry is removed and write() returns true.
 * read() returns null for a miss; an unreadable (corrupt or incompatible)
 * entry is removed and reported as a miss. Backend failures raise
 * CacheException.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Redis, APCu, filesystem and memory behind one port.
 * Spec V4: contract (hexagonal port); adapters are final classes.
 *
 * Preconditions: keys arrive validated by the calling cache.
 * Thread-safety: implementation-defined.
 *
 * Side effects: backend I/O.
 *
 * Example:
 *     $adapter->write('k', CacheEntry::withTtl($value, 60, microtime(true)), 60);
 *
 * References: ADR-010; SPEC-001 §5, §7.
 *
 * @see https://alistair.cockburn.us/hexagonal-architecture/
 */
interface CacheAdapter
{
    /** @throws CacheException */
    public function read(string $key): mixed;

    /** @throws CacheException */
    public function write(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Idempotent: true when the key no longer exists after the call, absent
     * keys included; false only when the backend refused the deletion.
     */
    public function remove(string $key): bool;

    public function exists(string $key): bool;

    /** Removes every entry owned by this adapter (its prefix, directory or memory). */
    public function flush(): bool;

    /**
     * @param list<string> $keys
     *
     * @return array<array-key, mixed> key => value, null for misses
     *                                 (numeric-string keys come back as int)
     */
    public function readMultiple(array $keys): array;

    /**
     * @param array<array-key, mixed> $values keys are cast to string
     */
    public function writeMultiple(array $values, ?int $ttl = null): bool;

    /**
     * Idempotent like remove().
     *
     * @param list<string> $keys
     */
    public function removeMultiple(array $keys): bool;

    public function stats(): AdapterStats;

    public function name(): string;

    public function isAvailable(): bool;
}
