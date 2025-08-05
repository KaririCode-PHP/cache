<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\MultiOperationCacheable;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Metrics\CacheMetrics;

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
    MultiOperationCacheable,
    AtomicOperable,
    Taggable,
    TopicSubscribable,
    CompareAndSwapAware
{
    /**
     * Enhanced get method with callback and stampede protection.
     *
     * @param string $key the cache key
     * @param callable|null $callback the callback to generate value if not cached
     * @param iterable $tags optional tags for cache entry
     *
     * @return mixed the cached or generated value
     */
    public function getWithCallback(
        string $key,
        ?callable $callback = null,
        iterable $tags = []
    ): mixed;

    /**
     * Warms the cache with predefined data.
     *
     * @param iterable $data associative iterable of key-value pairs to cache
     */
    public function warm(iterable $data): void;

    /**
     * Gets high-level performance metrics for the cache instance.
     *
     * @return CacheMetrics cache performance metrics as an immutable object
     */
    public function getMetrics(): CacheMetrics;

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
     * @param iterable $options maintenance options
     *
     * @return bool true if maintenance was successful, false otherwise
     */
    public function maintenance(iterable $options = []): bool;
}
