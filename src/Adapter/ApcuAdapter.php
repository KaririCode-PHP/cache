<?php

declare(strict_types=1);

namespace KaririCode\Cache\Adapter;

use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\AdapterStats;

/**
 * APCu shared-memory adapter: one cache per server, shared by the PHP-FPM
 * workers of one pool.
 *
 * APCu stores PHP values natively (no Serializer). APCu treats TTL 0 as
 * "never expire", so a TTL <= 0 is translated into a delete before it
 * reaches apcu_store(). flush() with a prefix deletes only that prefix;
 * without a prefix it calls apcu_clear_cache(), which empties the whole
 * APCu segment of the SAPI.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): same CacheAdapter port as Redis.
 * Spec V4: Entity (hit/miss counters); fails fast when APCu is unusable.
 *
 * Preconditions: ext-apcu loaded and enabled (apc.enable_cli=1 on the CLI); keys already validated by the calling cache.
 * Thread-safety: one instance per worker; the APCu segment is shared by the processes of one pool.
 *
 * Side effects: reads and writes the APCu segment; flush() without a prefix clears the whole segment.
 *
 * Example:
 *     $cache = new Cache(new ApcuAdapter(prefix: 'app:'));
 *
 * References: ADR-010; SPEC-001 §7.
 *
 * @see https://www.php.net/manual/en/function.apcu-store.php
 */
final class ApcuAdapter implements CacheAdapter
{
    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;
    private int $deletes = 0;

    public function __construct(
        private readonly string $prefix = '',
    ) {
        if (!$this->isAvailable()) {
            throw ConfigurationException::extensionMissing('apcu');
        }
    }

    // ── CacheAdapter ─────────────────────────────────────────────────────

    #[\Override]
    public function read(string $key): mixed
    {
        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        if (true !== $success) {
            ++$this->misses;

            return null;
        }

        ++$this->hits;

        return $value;
    }

    #[\Override]
    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (null !== $ttl && $ttl <= 0) {
            return $this->remove($key);
        }

        ++$this->writes;

        return apcu_store($this->prefix . $key, $value, $ttl ?? 0);
    }

    #[\Override]
    public function remove(string $key): bool
    {
        if (apcu_delete($this->prefix . $key)) {
            ++$this->deletes;
        }

        return true;
    }

    #[\Override]
    public function exists(string $key): bool
    {
        return apcu_exists($this->prefix . $key);
    }

    #[\Override]
    public function flush(): bool
    {
        if ('' === $this->prefix) {
            return apcu_clear_cache();
        }

        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', APC_ITER_KEY);

        return false !== apcu_delete($iterator);
    }

    #[\Override]
    public function readMultiple(array $keys): array
    {
        $prefixed = array_map(fn(string $key): string => $this->prefix . $key, $keys);
        $fetched = apcu_fetch($prefixed);
        $fetched = is_array($fetched) ? $fetched : [];
        $results = [];

        foreach ($keys as $key) {
            if (array_key_exists($this->prefix . $key, $fetched)) {
                $results[$key] = $fetched[$this->prefix . $key];
                ++$this->hits;
            } else {
                $results[$key] = null;
                ++$this->misses;
            }
        }

        return $results;
    }

    #[\Override]
    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        if (null !== $ttl && $ttl <= 0) {
            return $this->removeMultiple(array_map(strval(...), array_keys($values)));
        }

        $prefixed = [];

        foreach ($values as $key => $value) {
            $prefixed[$this->prefix . $key] = $value;
        }

        $failed = apcu_store($prefixed, null, $ttl ?? 0);
        $this->writes += count($values);

        return [] === $failed;
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
        $info = apcu_cache_info(true);

        return new AdapterStats(
            hits: $this->hits,
            misses: $this->misses,
            writes: $this->writes,
            deletes: $this->deletes,
            itemCount: self::wholeNumber($info, 'num_entries'),
            memoryBytes: self::wholeNumber($info, 'mem_size'),
            adapterName: $this->name(),
        );
    }

    /**
     * APCu reports some counters as floats (mem_size is 512.0 in 5.1.28),
     * so any finite number is accepted and truncated; anything else is 0.
     */
    private static function wholeNumber(mixed $info, string $field): int
    {
        $value = is_array($info) ? ($info[$field] ?? null) : null;

        return is_int($value) || (is_float($value) && is_finite($value)) ? (int) $value : 0;
    }

    #[\Override]
    public function name(): string
    {
        return 'apcu';
    }

    /**
     * False when the extension is missing or disabled (apc.enable_cli=0 on
     * the CLI).
     */
    #[\Override]
    public function isAvailable(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }
}
