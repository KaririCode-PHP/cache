<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tag;

use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Contract\TagAwareCache as TagAwareCacheContract;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Scope\ScopedCache;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\ValueObject\TaggedEntry;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * Cache with tag invalidation by versioned tags (ADR-012).
 *
 * Each tag has a version stored under "__tag_version:{tag}". An entry
 * records the versions of its tags, captured before its value is computed;
 * it is valid only while every recorded version still equals the current
 * one. invalidateTags() writes a new random version: concurrent
 * invalidations cannot cancel each other, and a tag whose version was lost
 * (evicted, flushed) invalidates its entries instead of resurrecting them.
 * All writes persist a TaggedEntry, so user arrays are never mistaken for
 * envelopes and a cached null is a hit. remember()/rememberStale() use the
 * injected StampedePrevention, like Cache.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): works over any CacheAdapter.
 * Spec V4: Entity (composes an adapter and a strategy).
 *
 * Preconditions: tags follow the native key rule; keys do not start with __tag_version:.
 * Thread-safety: no state of its own; as safe as the adapter.
 *
 * Side effects: reads 1 + n keys per tagged read; writes tag version keys.
 *
 * Example:
 *     $orders = $cache->remember('user:42:orders', fn () => $repository->ordersOf(42), 600, ['user:42']);
 *
 * References: ADR-012; SPEC-001 §8.
 *
 * @see https://symfony.com/doc/current/components/cache/cache_invalidation.html
 */
final readonly class TagAwareCache implements TagAwareCacheContract
{
    private const string TAG_PREFIX = '__tag_version:';

    public function __construct(
        private CacheAdapter $adapter,
        private StampedePrevention $stampedePrevention = new XFetchPrevention(),
        private ClockInterface $clock = new SystemClock(),
        private Randomizer $randomizer = new Randomizer(),
    ) {}

    // ── Tagging ──────────────────────────────────────────────────────────

    #[\Override]
    public function setTagged(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $this->validateKey($key);

        if (null !== $ttl && $ttl <= 0) {
            return $this->delete($key);
        }

        return $this->adapter->write(key: $key, value: new TaggedEntry($value, $this->currentVersions($tags)), ttl: $ttl);
    }

    #[\Override]
    public function invalidateTags(array $tags): bool
    {
        $success = true;

        foreach ($tags as $tag) {
            InvalidKeyException::validate($tag);
            $success = $this->adapter->write(self::TAG_PREFIX . $tag, $this->newVersion()) && $success;
        }

        return $success;
    }

    // ── Core Operations ──────────────────────────────────────────────────

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $stored = $this->adapter->read($key);

        if ($stored instanceof TaggedEntry) {
            return $this->isCurrent($stored) ? $stored->value : $default;
        }

        return $stored ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->validateKey($key);

        if (null !== $ttl && $ttl <= 0) {
            return $this->delete($key);
        }

        return $this->adapter->write(key: $key, value: new TaggedEntry($value), ttl: $ttl);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->adapter->remove($key);
    }

    #[\Override]
    public function has(string $key): bool
    {
        $this->validateKey($key);

        $stored = $this->adapter->read($key);

        if ($stored instanceof TaggedEntry) {
            return $this->isCurrent($stored);
        }

        return null !== $stored;
    }

    #[\Override]
    public function clear(): bool
    {
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

    // ── Fetch-or-Compute ─────────────────────────────────────────────────

    #[\Override]
    public function remember(string $key, callable $factory, ?int $ttl = null, array $tags = []): mixed
    {
        $this->validateKey($key);

        if (null !== $ttl && $ttl <= 0) {
            $this->delete($key);

            return $factory();
        }

        $stored = $this->adapter->read($key);

        if ($stored instanceof TaggedEntry && $this->isCurrent($stored) && !$this->mustRecompute($key, $stored)) {
            return $stored->value;
        }

        $entry = $this->compute(factory: $factory, versions: $this->currentVersions($tags), logicalTtl: $ttl);
        $this->adapter->write(key: $key, value: $entry, ttl: $ttl);

        return $entry->value;
    }

    #[\Override]
    public function rememberStale(string $key, callable $factory, int $freshTtl, int $staleTtl): mixed
    {
        $this->validateKey($key);
        ConfigurationException::validateStaleWindow($freshTtl, $staleTtl);

        $stored = $this->adapter->read($key);

        if ($stored instanceof TaggedEntry && $this->isCurrent($stored) && !$this->mustRecompute($key, $stored)) {
            return $stored->value;
        }

        $entry = $this->compute(factory: $factory, versions: [], logicalTtl: $freshTtl);
        $this->adapter->write(key: $key, value: $entry, ttl: $staleTtl);

        return $entry->value;
    }

    #[\Override]
    public function withScope(string $scope): Cache
    {
        return new ScopedCache($this, $scope);
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function validateKey(string $key): void
    {
        InvalidKeyException::validate($key);

        if (str_starts_with($key, self::TAG_PREFIX)) {
            throw InvalidKeyException::reservedPrefix(self::TAG_PREFIX);
        }
    }

    /**
     * Versions are read (and created when missing) before the factory runs,
     * so an invalidation that happens during the computation wins.
     *
     * @param list<string> $tags
     *
     * @return array<string, int>
     */
    private function currentVersions(array $tags): array
    {
        $versions = [];

        foreach ($tags as $tag) {
            InvalidKeyException::validate($tag);

            $version = $this->adapter->read(self::TAG_PREFIX . $tag);

            if (!is_int($version)) {
                $version = $this->newVersion();
                $this->adapter->write(self::TAG_PREFIX . $tag, $version);
            }

            $versions[$tag] = $version;
        }

        return $versions;
    }

    private function isCurrent(TaggedEntry $entry): bool
    {
        foreach ($entry->tagVersions as $tag => $version) {
            if ($this->adapter->read(self::TAG_PREFIX . $tag) !== $version) {
                return false;
            }
        }

        return true;
    }

    private function mustRecompute(string $key, TaggedEntry $entry): bool
    {
        return null !== $entry->expiresAt
            && $this->stampedePrevention->shouldRecompute(key: $key, currentTime: $this->now(), expiryTime: $entry->expiresAt, delta: $entry->delta);
    }

    /**
     * @param array<string, int> $versions
     */
    private function compute(callable $factory, array $versions, ?int $logicalTtl): TaggedEntry
    {
        $start = hrtime(true);
        $value = $factory();
        $delta = (float) (hrtime(true) - $start) / 1e9;

        return new TaggedEntry(value: $value, tagVersions: $versions, expiresAt: null === $logicalTtl ? null : $this->now() + (float) $logicalTtl, delta: $delta);
    }

    private function newVersion(): int
    {
        return $this->randomizer->getInt(1, PHP_INT_MAX);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
