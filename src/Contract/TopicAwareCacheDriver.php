<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\ValueObject\Topic;

/**
 *  * Interface TopicAwareCacheDriver.
 *  *
 *  * Extends the base CacheDriver to include support for topic-based invalidation.
 *  * Drivers implementing this interface can manage subscriptions between cache keys
 *  * and topics, allowing for event-driven invalidation.
 *  *.
 *
 *  * @category  Cache
 *  *
 *
 *  * @author    Walmir Silva <walmir.silva@kariricode.org>
 *  * @license   MIT
 *  *
 *
 *  * @see       https://kariricode.org/
 *  */
interface TopicAwareCacheDriver extends CacheDriver
{
    /**
     * Subscribes a cache key to one or more topics.
     *
     * @param string $key the cache item key
     * @param iterable<string|Topic> $topics an iterable of Topic objects to subscribe to
     */
    public function subscribeTo(string $key, iterable $topics): void;

    /**
     * Unsubscribes a cache key from one or more topics.
     *
     * @param iterable<string|Topic> $topics an iterable of Topic objects to unsubscribe from
     *
     * @return bool true if the operation was successful, false otherwise
     */
    public function invalidateTopics(iterable $topics): bool;

    /**
     * Retrieves all topics a cache key is subscribed to.
     *
     * @param string $key the cache item key
     *
     * @return iterable<string, Topic> an iterable of Topic objects
     */
    public function getSubscribedTopics(string $key): iterable;

    /**
     * Retrieves all cache keys subscribed to a specific topic.
     *
     * @param string $topic the topic name
     *
     * @return iterable<string> an iterable of cache item keys
     */
    public function getSubscribersForTopic(string $topic): iterable;
}
