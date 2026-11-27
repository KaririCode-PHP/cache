<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Event\NullEventListener;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Scope\ScopedCache;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\ValueObject\CacheEntry;
use Psr\Clock\ClockInterface;

/**
 * Primary cache: one adapter plus capacity policy, stampede prevention and
 * observation hooks.
 *
 * Every write persists a CacheEntry envelope, so any worker reading the key
 * receives the logical expiry and the measured recompute cost that XFetch
 * needs (ADR-009), and a cached null is distinguishable from a miss.
 *
 * Capacity is the eviction policy's `capacity` (default SIEVE with 10 000
 * entries; 0 = unbounded). It is tracked in process memory: it bounds
 * process-local stores and long-running workers; with a shared backend
 * under PHP-FPM, size the backend instead (ADR-010).
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): any CacheAdapter, any policy.
 * ARFA 1.3 P5 (Continuous Observability): CacheEventListener on every outcome.
 * Spec V4: Entity (composes collaborators; state lives in them).
 *
 * Preconditions: keys follow the native key rule; TTL null = no expiry, <= 0 deletes (ADR-010).
 * Thread-safety: no state of its own; the adapter, policy and listener decide; one instance per worker under level 2.
 *
 * Side effects: backend reads and writes, policy bookkeeping, listener hooks and, in remember(), the factory call.
 *
 * Example:
 *     $report = (new Cache(new RedisAdapter(prefix: 'app:')))->remember('report:daily', fn () => buildReport(), 600);
 *
 * References: ADR-001; ADR-002; ADR-009; ADR-010; SPEC-001 §6.
 *
 * @see https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
 */
final readonly class Cache implements CacheContract
{
    public function __construct(
        private CacheAdapter $adapter,
        private EvictionPolicy $evictionPolicy = new SievePolicy(),
        private StampedePrevention $stampedePrevention = new XFetchPrevention(),
        private CacheEventListener $eventListener = new NullEventListener(),
        private ClockInterface $clock = new SystemClock(),
    ) {}

    // ── Core Operations ──────────────────────────────────────────────────

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        InvalidKeyException::validate($key);

        $stored = $this->adapter->read($key);

        if (null === $stored) {
            $this->recordMiss($key);

            return $default;
        }

        $this->evictionPolicy->recordAccess($key);
        $this->eventListener->onHit($key);

        return $stored instanceof CacheEntry ? $stored->value : $stored;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        InvalidKeyException::validate($key);

        if (null !== $ttl && $ttl <= 0) {
            $this->delete($key);

            return true;
        }

        return $this->store(key: $key, entry: CacheEntry::withTtl(value: $value, ttl: $ttl, now: $this->now()), ttl: $ttl);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        InvalidKeyException::validate($key);

        $result = $this->adapter->remove($key);
        $this->evictionPolicy->recordRemoval($key);
        $this->eventListener->onDelete($key);

        return $result;
    }

    #[\Override]
    public function has(string $key): bool
    {
        InvalidKeyException::validate($key);

        return $this->adapter->exists($key);
    }

    #[\Override]
    public function clear(): bool
    {
        $this->evictionPolicy->clear();

        return $this->adapter->flush();
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

    // ── Fetch-or-Compute (XFetch) ────────────────────────────────────────

    #[\Override]
    public function remember(string $key, callable $factory, ?int $ttl = null, array $tags = []): mixed
    {
        InvalidKeyException::validate($key);

        if (null !== $ttl && $ttl <= 0) {
            $this->delete($key);

            return $factory();
        }

        $stored = $this->adapter->read($key);

        if ($stored instanceof CacheEntry && !$this->mustRecompute($key, $stored)) {
            return $this->hit($key, $stored);
        }

        $this->announceRecompute($key, $stored);
        $entry = $this->compute(factory: $factory, logicalTtl: $ttl, tags: $tags);
        $this->store(key: $key, entry: $entry, ttl: $ttl);

        return $entry->value;
    }

    // ── Stale-While-Revalidate ───────────────────────────────────────────

    #[\Override]
    public function rememberStale(string $key, callable $factory, int $freshTtl, int $staleTtl): mixed
    {
        InvalidKeyException::validate($key);
        ConfigurationException::validateStaleWindow($freshTtl, $staleTtl);

        $stored = $this->adapter->read($key);

        if ($stored instanceof CacheEntry && !$this->mustRecompute($key, $stored)) {
            return $this->hit($key, $stored);
        }

        // Logical (fresh) horizon drives stampede prevention; the physical
        // (stale) horizon keeps the value servable meanwhile.
        $this->announceRecompute($key, $stored);
        $entry = $this->compute(factory: $factory, logicalTtl: $freshTtl, tags: []);
        $this->store(key: $key, entry: $entry, ttl: $staleTtl);

        return $entry->value;
    }

    // ── Scoping ──────────────────────────────────────────────────────────

    #[\Override]
    public function withScope(string $scope): CacheContract
    {
        return new ScopedCache($this, $scope);
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function mustRecompute(string $key, CacheEntry $entry): bool
    {
        if (null === $entry->expiresAt) {
            return false;
        }

        return $this->stampedePrevention->shouldRecompute(key: $key, currentTime: $this->now(), expiryTime: $entry->expiresAt, delta: $entry->delta);
    }

    private function hit(string $key, CacheEntry $entry): mixed
    {
        $this->evictionPolicy->recordAccess($key);
        $this->eventListener->onHit($key);

        return $entry->value;
    }

    /**
     * A stored entry selected for early recomputation is reported as such;
     * anything else is a miss.
     */
    private function announceRecompute(string $key, mixed $stored): void
    {
        if ($stored instanceof CacheEntry) {
            $gap = null === $stored->expiresAt ? 0.0 : max(0.0, $stored->expiresAt - $this->now());
            $this->eventListener->onStampedeRecompute($key, $gap);

            return;
        }

        $this->recordMiss($key);
    }

    /**
     * Run the factory and measure its cost (δ) with the monotonic clock.
     *
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

    private function store(string $key, CacheEntry $entry, ?int $ttl): bool
    {
        $isUpdate = $this->adapter->exists($key);

        if (!$isUpdate) {
            $this->enforceCapacity();
        }

        if (!$this->adapter->write(key: $key, value: $entry, ttl: $ttl)) {
            return false;
        }

        $isUpdate
            ? $this->evictionPolicy->recordAccess($key)
            : $this->evictionPolicy->recordInsertion($key);
        $this->eventListener->onWrite($key, $ttl);

        return true;
    }

    /**
     * A miss also drops the key from the policy, so entries that expired in
     * the backend stop occupying tracked capacity.
     */
    private function recordMiss(string $key): void
    {
        $this->evictionPolicy->recordRemoval($key);
        $this->eventListener->onMiss($key);
    }

    private function enforceCapacity(): void
    {
        $capacity = $this->evictionPolicy->capacity;

        if (0 === $capacity) {
            return;
        }

        while ($this->evictionPolicy->count() >= $capacity) {
            $result = $this->evictionPolicy->evict();

            if (!$result->success || null === $result->evictedKey) {
                return;
            }

            $this->adapter->remove($result->evictedKey);
            $this->eventListener->onEviction($result->evictedKey, $this->evictionPolicy->name());
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
