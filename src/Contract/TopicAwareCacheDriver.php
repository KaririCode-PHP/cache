<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface TopicAwareCacheDriver.
 *
 * Defines the contract for cache drivers that support topic-based subscriptions
 * for cache invalidation. This allows items to be invalidated based on events
 * published to specific topics.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TopicAwareCacheDriver extends CacheDriver
{
    /**
     * Subscribes a cache item to one or more invalidation topics.
     *
     * @param string $key the unique cache key
     * @param array $topics the topics to subscribe to
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Triggers invalidation for all items subscribed to the specified topics.
     *
     * @param array $topics the topics to trigger invalidation for
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTopics(array $topics): bool;
}
