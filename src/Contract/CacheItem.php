<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Enhanced cache item interface with tagging and topic subscription.
 */
interface CacheItem extends \Psr\Cache\CacheItemInterface
{
    /**
     * Add tags to this cache item.
     */
    public function tag(array $tags): static;

    /**
     * Get tags associated with this item.
     */
    public function getTags(): array;

    /**
     * Subscribe to invalidation topics.
     */
    public function subscribeTo(array $topics): static;

    /**
     * Get subscribed topics.
     */
    public function getTopics(): array;
}
