<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Interface CacheEventListener.
 *
 * Defines the contract for listeners that handle cache-related events.
 * Implementations of this interface can be used to monitor cache operations
 * such as hits, misses, sets, and deletes for logging or metrics purposes.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheEventListener
{
    /**
     * Handles the event of a cache hit.
     *
     * @param string $key the key of the item found in the cache
     * @param mixed $value the value of the item
     */
    public function onCacheHit(string $key, mixed $value): void;

    /**
     * Handles the event of a cache miss.
     *
     * @param string $key the key of the item not found in the cache
     */
    public function onCacheMiss(string $key): void;

    /**
     * Handles the event when a cache item is set.
     *
     * @param string $key the key of the item being set
     * @param mixed $value the value of the item being set
     */
    public function onCacheSet(string $key, mixed $value): void;

    /**
     * Handles the event when a cache item is deleted.
     *
     * @param string $key the key of the item being deleted
     */
    public function onCacheDelete(string $key): void;

    /**
     * Handles the event when the cache is cleared.
     */
    public function onCacheClear(): void;

    /**
     * Returns statistics about the events that have occurred.
     *
     * @return array<string, int> an associative array of event types to their counts
     */
    public function getStats(): array;

    /**
     * Sets the event dispatcher instance.
     *
     * @param EventDispatcherInterface|null $eventDispatcher the event dispatcher
     */
    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void;

    /**
     * Gets the event dispatcher instance.
     *
     * @return EventDispatcherInterface the event dispatcher
     */
    public function getEventDispatcher(): EventDispatcherInterface;
}
