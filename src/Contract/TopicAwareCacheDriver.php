<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;

/**
 * Extends the base CacheDriver to include support for topic-based invalidation.
 *
 * Drivers implementing this interface can manage subscriptions between cache keys
 * and topics, allowing for event-driven invalidation. It now extends the main
 * TopicSubscribable interface to ensure a complete and consistent contract.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TopicAwareCacheDriver extends CacheDriver, TopicSubscribable
{
    // This interface remains empty by design.
    // Its purpose is to group the CacheDriver and TopicSubscribable contracts,
    // signaling that a driver fully supports all topic-related features.
}
