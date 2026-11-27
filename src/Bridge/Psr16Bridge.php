<?php

declare(strict_types=1);

namespace KaririCode\Cache\Bridge;

use KaririCode\Cache\Bridge\Exception\Psr16CacheException;
use KaririCode\Cache\Bridge\Exception\Psr16InvalidArgumentException;
use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidKeyException;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 (Simple Cache) adapter over any KaririCode Cache.
 *
 * Boundary rules (ADR-011): keys are validated with the full PSR reserved
 * set {}()/\@: (the native API also accepts ':'); invalid keys raise
 * Psr16InvalidArgumentException and backend failures raise
 * Psr16CacheException, both implementing the PSR-16 interfaces; a TTL of
 * zero or less, integer or DateInterval, deletes the entry; deleting a
 * missing key returns true.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): PSR types stay in Bridge/.
 * Spec V4: stateless adapter (final readonly class).
 *
 * Preconditions: keys follow the PSR reserved set {}()/\@:.
 * Thread-safety: stateless; as safe as the wrapped cache.
 *
 * Side effects: delegates to the wrapped cache and translates its exceptions.
 *
 * Example:
 *     $simple = new Psr16Bridge($cache); $simple->set('user.42', $user, new \DateInterval('PT5M'));
 *
 * References: ADR-011; SPEC-001 §2.3.
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */
final readonly class Psr16Bridge implements CacheInterface
{
    public function __construct(
        private Cache $cache,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validate($key);

        return $this->guard(fn(): mixed => $this->cache->get($key, $default));
    }

    #[\Override]
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->validate($key);
        $seconds = $this->seconds($ttl);

        return $this->guard(fn(): bool => $this->cache->set(key: $key, value: $value, ttl: $seconds));
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $this->validate($key);

        return $this->guard(fn(): bool => $this->cache->delete($key));
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->guard(fn(): bool => $this->cache->clear());
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return iterable<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->validateAll($keys);

        return $this->guard(fn(): iterable => $this->cache->getMultiple($keys, $default));
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $validated = [];

        foreach ($values as $key => $value) {
            $key = self::keyOf($key);
            $this->validate($key);
            $validated[$key] = $value;
        }

        $seconds = $this->seconds($ttl);

        return $this->guard(fn(): bool => $this->cache->setMultiple($validated, $seconds));
    }

    /**
     * @param iterable<mixed> $keys
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->validateAll($keys);

        return $this->guard(fn(): bool => $this->cache->deleteMultiple($keys));
    }

    #[\Override]
    public function has(string $key): bool
    {
        $this->validate($key);

        return $this->guard(fn(): bool => $this->cache->has($key));
    }

    /**
     * The wrapped native cache (remember(), rememberStale(), withScope()).
     */
    public function unwrap(): Cache
    {
        return $this->cache;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function validate(string $key): void
    {
        try {
            InvalidKeyException::validate($key, psrStrict: true);
        } catch (InvalidKeyException $e) {
            throw Psr16InvalidArgumentException::fromInvalidKey($e);
        }
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     */
    private function validateAll(iterable $keys): array
    {
        $validated = [];

        foreach ($keys as $key) {
            $key = self::keyOf($key);
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
            throw Psr16CacheException::fromCacheFailure($e);
        }
    }

    private static function keyOf(mixed $key): string
    {
        if (is_string($key)) {
            return $key;
        }

        if (is_int($key)) {
            return (string) $key;
        }

        throw Psr16InvalidArgumentException::fromInvalidKey(InvalidKeyException::empty());
    }

    private function seconds(int|\DateInterval|null $ttl): ?int
    {
        if (!$ttl instanceof \DateInterval) {
            return $ttl;
        }

        $now = $this->clock->now();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }
}
