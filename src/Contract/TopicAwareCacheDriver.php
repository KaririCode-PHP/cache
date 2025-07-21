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
     * Subscribes a cache key to one or more invalidation topics.
     *
     * @param string $key the cache key to subscribe
     * @param Topic[] $topics an array of topics // CORREÇÃO: Tipo atualizado para Topic[]
     */
    public function subscribeTo(string $key, array $topics): void;

    /**
     * Invalidates all cache keys subscribed to the given topics.
     *
     * @param Topic[] $topics the topics to invalidate // CORREÇÃO: Tipo atualizado para Topic[]
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTopics(array $topics): bool;
}
