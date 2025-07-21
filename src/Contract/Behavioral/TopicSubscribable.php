<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * TopicSubscribable interface.
 *
 * This interface defines methods for topic-based cache invalidation.
 * Topics allow subscribing cache items to invalidation events
 * triggered by specific business logic or external systems.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TopicSubscribable
{
    /**
     * Subscribe a cache item to invalidation topics.
     *
     * @param string $key the unique cache key
     * @param array $topics the topics to subscribe to
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Unsubscribe a cache item from invalidation topics.
     *
     * @param string $key the unique cache key
     * @param array $topics the topics to unsubscribe from
     */
    public function unsubscribeFrom(string $key, array $topics): void;

    /**
     * Trigger invalidation for all items subscribed to specified topics.
     *
     * @param array $topics the topics to trigger invalidation for
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTopics(array $topics): bool;

    /**
     * Get all topics a cache item is subscribed to.
     *
     * @param string $key the unique cache key
     *
     * @return array<string> an array of topics the cache item is subscribed to
     */
    public function getSubscribedTopics(string $key): array;

    /**
     * Get all cache items subscribed to a specific topic.
     *
     * @param string $topic the topic to search for
     *
     * @return array<string> an array of cache keys subscribed to the topic
     */
    public function getSubscribersForTopic(string $topic): array;
}
