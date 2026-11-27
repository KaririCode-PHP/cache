<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidKeyException;

/**
 * Primary cache contract: PSR-16-shaped operations plus fetch-or-compute
 * (remember), stale-while-revalidate (rememberStale) and scoping.
 *
 * Keys follow the native key rule (InvalidKeyException): non-empty, none of
 * {}()/\@; ':' is allowed. TTL semantics: null = no expiry; a TTL <= 0
 * deletes the entry, as PSR-16 requires. Implementations that persist a
 * CacheEntry envelope (Cache) distinguish a cached null from a miss;
 * implementations that store raw values (LayeredCache) cannot.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): no backend type crosses the contract.
 * Spec V4: contract; implementations are final classes.
 *
 * Preconditions: implementations validate keys and apply the TTL semantics of ADR-010.
 * Thread-safety: implementation-defined; this package's implementations keep no request state.
 *
 * Side effects: implementation-defined (backend I/O, factory calls).
 *
 * Example:
 *     function report(Cache $cache): array { return $cache->remember('report', fn () => build(), 600); }
 *
 * References: SPEC-001 §5, §6.
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */
interface Cache
{
    /**
     * @throws InvalidKeyException
     * @throws CacheException
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param int|null $ttl Seconds; null = no expiry; <= 0 deletes the entry.
     *
     * @throws InvalidKeyException
     * @throws CacheException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;

    public function clear(): bool;

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed> key => value (or $default on miss)
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * Keys are cast to string: PHP turns numeric string array keys into ints.
     *
     * @param iterable<array-key, mixed> $values
     */
    public function setMultiple(iterable $values, ?int $ttl = null): bool;

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * Fetch-or-compute. Implementations with stampede prevention measure the
     * factory's cost (delta) and may recompute before expiry (XFetch,
     * Vattani et al., VLDB 2015); the caller that recomputes pays the cost.
     *
     * @template T
     *
     * @param callable(): T $factory
     * @param list<string>  $tags Honoured by TagAwareCache; other
     *                            implementations only record or ignore them.
     *
     * @return T
     */
    public function remember(string $key, callable $factory, ?int $ttl = null, array $tags = []): mixed;

    /**
     * Stale-while-revalidate adapted to a request model (after RFC 5861).
     *
     * The value is fresh for $freshTtl seconds and stays stored until
     * $staleTtl. The configured stampede strategy decides who recomputes:
     *   - XFetch (default): early recomputations are spread before the
     *     fresh horizon; after it, every caller recomputes until one of
     *     them stores the new value (no single-flight);
     *   - LockingPrevention: after the fresh horizon one caller per key
     *     recomputes and the others get the stored (stale) value, inside
     *     one process (long-running worker).
     * Recomputation is synchronous; there is no background refresh.
     *
     * @template T
     *
     * @param callable(): T $factory
     * @param int $freshTtl Seconds the value is fresh (>= 1).
     * @param int $staleTtl Seconds the value stays servable (>= $freshTtl).
     *
     * @return T
     *
     * @throws \KaririCode\Cache\Exception\ConfigurationException when the window is invalid
     */
    public function rememberStale(string $key, callable $factory, int $freshTtl, int $staleTtl): mixed;

    /**
     * Namespace-scoped view: keys are prefixed with "{$scope}:".
     */
    public function withScope(string $scope): self;
}
