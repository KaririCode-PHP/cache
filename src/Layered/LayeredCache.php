<?php

declare(strict_types=1);

namespace KaririCode\Cache\Layered;

use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Scope\ScopedCache;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\ValueObject\CacheEntry;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * Multi-tier cache: read from the fastest layer that has the key, write
 * through to every layer, promote hits to the faster layers.
 *
 * Every layer stores the same CacheEntry envelope. A promoted copy lives
 * min(layer TTL, time left before the entry's expiry), so promotion never
 * extends a value's life. Layer TTLs get a downward-only jitter of up to
 * 10% to spread expirations; an explicit TTL is never lengthened.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): any CacheAdapter can be a layer.
 * Spec V4: Entity (mutable layer list; fluent addLayer()).
 *
 * Preconditions: layers added fastest first; a layer TTL is null or > 0.
 * Thread-safety: mutable layer list: configure at boot, then share.
 *
 * Side effects: reads layers until a hit, writes through all layers, promotes hits.
 *
 * Example:
 *     $cache = (new LayeredCache())->addLayer(new ApcuAdapter('l1:'), 60)->addLayer(new RedisAdapter(prefix: 'l2:'), 3600);
 *
 * References: ADR-009; SPEC-001 §8.
 *
 * @see https://symfony.com/doc/current/components/cache/adapters/chain_adapter.html
 */
final class LayeredCache implements LayeredCacheContract
{
    /** @var list<array{adapter: CacheAdapter, ttl: int|null}> fastest first */
    private array $layers = [];

    public function __construct(
        private readonly StampedePrevention $stampedePrevention = new XFetchPrevention(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {}

    #[\Override]
    public function addLayer(CacheAdapter $adapter, ?int $ttl = null): static
    {
        if (null !== $ttl && $ttl <= 0) {
            throw ConfigurationException::invalidOption(option: 'layer ttl', constraint: 'must be null or > 0');
        }

        $this->layers[] = ['adapter' => $adapter, 'ttl' => $ttl];

        return $this;
    }

    #[\Override]
    public function layerCount(): int
    {
        return count($this->layers);
    }

    // ── Core Operations ──────────────────────────────────────────────────

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        InvalidKeyException::validate($key);

        $stored = $this->lookup($key);

        if (null === $stored) {
            return $default;
        }

        return $stored instanceof CacheEntry ? $stored->value : $stored;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        InvalidKeyException::validate($key);

        if (null !== $ttl && $ttl <= 0) {
            return $this->delete($key);
        }

        return $this->writeThrough(key: $key, entry: CacheEntry::withTtl(value: $value, ttl: $ttl, now: $this->now()), ttl: $ttl);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        InvalidKeyException::validate($key);

        $success = true;

        foreach ($this->layers as $layer) {
            $success = $layer['adapter']->remove($key) && $success;
        }

        return $success;
    }

    #[\Override]
    public function has(string $key): bool
    {
        InvalidKeyException::validate($key);

        foreach ($this->layers as $layer) {
            if ($layer['adapter']->exists($key)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function clear(): bool
    {
        $success = true;

        foreach ($this->layers as $layer) {
            $success = $layer['adapter']->flush() && $success;
        }

        return $success;
    }

    // ── Batch Operations ─────────────────────────────────────────────────

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    #[\Override]
    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            $success = $this->set(key: (string) $key, value: $value, ttl: $ttl) && $success;
        }

        return $success;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    // ── Fetch-or-Compute ─────────────────────────────────────────────────

    #[\Override]
    public function remember(string $key, callable $factory, ?int $ttl = null, array $tags = []): mixed
    {
        InvalidKeyException::validate($key);

        if (null !== $ttl && $ttl <= 0) {
            $this->delete($key);

            return $factory();
        }

        $stored = $this->lookup($key);

        if (null !== $stored && !$this->mustRecompute($key, $stored)) {
            return $stored instanceof CacheEntry ? $stored->value : $stored;
        }

        $entry = $this->compute(factory: $factory, logicalTtl: $ttl, tags: $tags);
        $this->writeThrough(key: $key, entry: $entry, ttl: $ttl);

        return $entry->value;
    }

    #[\Override]
    public function rememberStale(string $key, callable $factory, int $freshTtl, int $staleTtl): mixed
    {
        InvalidKeyException::validate($key);
        ConfigurationException::validateStaleWindow($freshTtl, $staleTtl);

        $stored = $this->lookup($key);

        if (null !== $stored && !$this->mustRecompute($key, $stored)) {
            return $stored instanceof CacheEntry ? $stored->value : $stored;
        }

        $entry = $this->compute(factory: $factory, logicalTtl: $freshTtl, tags: []);
        $this->writeThrough(key: $key, entry: $entry, ttl: $staleTtl);

        return $entry->value;
    }

    #[\Override]
    public function withScope(string $scope): Cache
    {
        return new ScopedCache($this, $scope);
    }

    // ── Internal ─────────────────────────────────────────────────────────

    /**
     * Raw values written by other clients carry no expiry metadata and are
     * served as they are.
     */
    private function mustRecompute(string $key, mixed $stored): bool
    {
        return $stored instanceof CacheEntry
            && null !== $stored->expiresAt
            && $this->stampedePrevention->shouldRecompute(key: $key, currentTime: $this->now(), expiryTime: $stored->expiresAt, delta: $stored->delta);
    }

    /**
     * @param list<string> $tags
     */
    private function compute(callable $factory, ?int $logicalTtl, array $tags): CacheEntry
    {
        // δ is a duration: measured on the monotonic clock, not on the wall clock.
        $start = hrtime(true);
        $value = $factory();
        $delta = (float) (hrtime(true) - $start) / 1e9;
        $now = $this->now();

        return new CacheEntry(value: $value, createdAt: $now, expiresAt: null === $logicalTtl ? null : $now + (float) $logicalTtl, delta: $delta, tags: $tags);
    }

    /**
     * First stored value from the fastest layer that has it, promoted to
     * the faster layers.
     */
    private function lookup(string $key): mixed
    {
        foreach ($this->layers as $index => $layer) {
            $stored = $layer['adapter']->read($key);

            if (null !== $stored) {
                $this->promote(key: $key, stored: $stored, hitIndex: $index);

                return $stored;
            }
        }

        return null;
    }

    private function writeThrough(string $key, CacheEntry $entry, ?int $ttl): bool
    {
        $success = true;

        foreach ($this->layers as $layer) {
            $layerTtl = null === $ttl ? $this->jitter($layer['ttl']) : $ttl;
            $success = $layer['adapter']->write(key: $key, value: $entry, ttl: $layerTtl) && $success;
        }

        return $success;
    }

    private function promote(string $key, mixed $stored, int $hitIndex): void
    {
        $remaining = null;

        if ($stored instanceof CacheEntry && null !== $stored->expiresAt) {
            $remaining = (int) floor($stored->expiresAt - $this->now());

            if ($remaining < 1) {
                return;
            }
        }

        for ($i = 0; $i < $hitIndex; ++$i) {
            $layerTtl = $this->jitter($this->layers[$i]['ttl']);
            $ttl = null === $layerTtl ? $remaining : (null === $remaining ? $layerTtl : min($layerTtl, $remaining));
            $this->layers[$i]['adapter']->write(key: $key, value: $stored, ttl: $ttl);
        }
    }

    /**
     * Downward-only jitter (up to 10%) so layers do not expire in lockstep.
     */
    private function jitter(?int $ttl): ?int
    {
        if (null === $ttl) {
            return null;
        }

        $spread = intdiv($ttl, 10);

        return $spread < 1 ? $ttl : $ttl - $this->randomizer->getInt(0, $spread);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
