<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\Cacheable;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Contract\ImmutableValue;

/**
 * Interface Cache.
 *
 * Main cache interface combining all caching functionalities.
 * This interface serves as the primary contract for cache implementations,
 * providing comprehensive caching capabilities including basic operations,
 * atomic operations, tagging, topic subscription, and metrics collection.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface Cache extends
    Cacheable,
    AtomicOperable,
    Taggable,
    TopicSubscribable
{
    /**
     * Enhanced get method with callback and stampede protection.
     *
     * @param string $key the cache key
     * @param callable|null $callback the callback to generate value if not cached
     * @param \DateInterval|int|null $ttl the time-to-live for the cached item
     * @param array $tags optional tags to associate with the cached value
     *
     * @return mixed the cached or generated value
     */
    public function getWithCallback(
        string $key,
        ?callable $callback = null,
        \DateInterval|int|null $ttl = null,
        array $tags = []
    ): mixed;

    /**
     * Warms the cache with predefined data.
     *
     * @param array $data associative array of key-value pairs to cache
     * @param \DateInterval|int|null $ttl the time-to-live for the cached items
     */
    public function warm(array $data, \DateInterval|int|null $ttl = null): void;

    /**
     * Gets cache statistics and information.
     *
     * @return ImmutableValue cache statistics as an immutable object
     */
    public function getStats(): ImmutableValue;

    /**
     * Gets the cache name/identifier.
     *
     * @return string the cache name
     */
    public function getName(): string;

    /**
     * Checks if the cache is available and operational.
     *
     * @return bool true if cache is healthy, false otherwise
     */
    public function isHealthy(): bool;

    /**
     * Performs cache maintenance operations.
     *
     * @param array $options maintenance options
     *
     * @return bool true if maintenance was successful, false otherwise
     */
    public function maintenance(array $options = []): bool;
}
