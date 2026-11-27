<?php

declare(strict_types=1);

namespace KaririCode\Cache\Adapter;

use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\ValueObject\AdapterStats;

/**
 * No-op adapter: every read is a miss and every write succeeds without
 * storing anything. Disables caching by configuration (tests, incident
 * mitigation) without touching call sites.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Null Object behind the CacheAdapter port.
 * Spec V4: stateless service.
 *
 * Preconditions: none.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $cache = new Cache(new NullAdapter()); // caching disabled
 *
 * References: SPEC-001 §7.
 *
 * @see https://en.wikipedia.org/wiki/Null_object_pattern
 */
final class NullAdapter implements CacheAdapter
{
    #[\Override]
    public function read(string $key): mixed
    {
        return null;
    }

    #[\Override]
    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        return true;
    }

    #[\Override]
    public function remove(string $key): bool
    {
        return true;
    }

    #[\Override]
    public function exists(string $key): bool
    {
        return false;
    }

    #[\Override]
    public function flush(): bool
    {
        return true;
    }

    #[\Override]
    public function readMultiple(array $keys): array
    {
        return array_fill_keys($keys, null);
    }

    #[\Override]
    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        return true;
    }

    #[\Override]
    public function removeMultiple(array $keys): bool
    {
        return true;
    }

    #[\Override]
    public function stats(): AdapterStats
    {
        return new AdapterStats(adapterName: 'null');
    }

    #[\Override]
    public function name(): string
    {
        return 'null';
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return true;
    }
}
