<?php

declare(strict_types=1);

namespace KaririCode\Cache\Bridge;

use KaririCode\Cache\Bridge\Exception\Psr6CacheException;
use KaririCode\Cache\Bridge\Exception\Psr6InvalidArgumentException;
use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidKeyException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * PSR-6 (Caching Interface) pool over any KaririCode Cache.
 *
 * Boundary rules (ADR-011): keys are validated with the PSR reserved set
 * {}()/\@:; getItem() and hasItem() see deferred items before commit(), as
 * PSR-6 requires; pending deferred items are committed on destruction; a
 * cached null is a hit (checked with has() when get() returns null);
 * failures raise exceptions implementing the PSR-6 interfaces.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): PSR types stay in Bridge/.
 * Spec V4: Entity (deferred queue mutated behind methods).
 *
 * Preconditions: keys follow the PSR reserved set {}()/\@:.
 * Thread-safety: holds deferred items; confine an instance to one request.
 *
 * Side effects: writes through the wrapped cache; commits pending deferred items on destruction.
 *
 * Example:
 *     $pool = new Psr6Bridge($cache); $pool->save($pool->getItem('report')->set($report)->expiresAfter(600));
 *
 * References: ADR-011; SPEC-001 §2.3.
 *
 * @see https://www.php-fig.org/psr/psr-6/
 */
final class Psr6Bridge implements CacheItemPoolInterface
{
    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function __destruct()
    {
        if ([] !== $this->deferred) {
            $this->commit();
        }
    }

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        $this->validate($key);

        $deferred = $this->deferred[$key] ?? null;

        if (null !== $deferred && !$this->isExpired($deferred)) {
            return new Psr6CacheItem(key: $key, value: $deferred->get(), isHit: true, clock: $this->clock);
        }

        return $this->guard(function () use ($key): CacheItemInterface {
            $value = $this->cache->get($key);
            $isHit = null !== $value || $this->cache->has($key);

            return new Psr6CacheItem(key: $key, value: $value, isHit: $isHit, clock: $this->clock);
        });
    }

    /**
     * @param array<array-key, mixed> $keys
     *
     * @return array<string, CacheItemInterface>
     */
    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        $items = [];

        foreach ($this->validateAll($keys) as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        $this->validate($key);

        $deferred = $this->deferred[$key] ?? null;

        if (null !== $deferred && !$this->isExpired($deferred)) {
            return true;
        }

        return $this->guard(fn(): bool => $this->cache->has($key));
    }

    #[\Override]
    public function clear(): bool
    {
        $this->deferred = [];

        return $this->guard(fn(): bool => $this->cache->clear());
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        $this->validate($key);
        unset($this->deferred[$key]);

        return $this->guard(fn(): bool => $this->cache->delete($key));
    }

    /**
     * @param array<array-key, mixed> $keys
     */
    #[\Override]
    public function deleteItems(array $keys): bool
    {
        $success = true;

        foreach ($this->validateAll($keys) as $key) {
            $success = $this->deleteItem($key) && $success;
        }

        return $success;
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        $key = $item->getKey();
        $this->validate($key);

        $ttl = $item instanceof Psr6CacheItem ? $item->resolveTtl($this->clock->now()) : null;

        return $this->guard(fn(): bool => $this->cache->set(key: $key, value: $item->get(), ttl: $ttl));
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->validate($item->getKey());
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    #[\Override]
    public function commit(): bool
    {
        $pending = $this->deferred;
        $this->deferred = [];
        $success = true;

        foreach ($pending as $item) {
            $success = $this->save($item) && $success;
        }

        return $success;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function validate(string $key): void
    {
        try {
            InvalidKeyException::validate($key, psrStrict: true);
        } catch (InvalidKeyException $e) {
            throw Psr6InvalidArgumentException::fromInvalidKey($e);
        }
    }

    /**
     * @param array<array-key, mixed> $keys
     *
     * @return list<string>
     */
    private function validateAll(array $keys): array
    {
        $validated = [];

        foreach ($keys as $key) {
            if (!is_string($key) && !is_int($key)) {
                throw Psr6InvalidArgumentException::fromInvalidKey(InvalidKeyException::empty());
            }

            $key = (string) $key;
            $this->validate($key);
            $validated[] = $key;
        }

        return $validated;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function guard(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (CacheException $e) {
            throw Psr6CacheException::fromCacheFailure($e);
        }
    }

    private function isExpired(CacheItemInterface $item): bool
    {
        if (!$item instanceof Psr6CacheItem) {
            return false;
        }

        $ttl = $item->resolveTtl($this->clock->now());

        return null !== $ttl && $ttl <= 0;
    }
}
