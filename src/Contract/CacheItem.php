<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\ValueObject\Tag;
use Psr\Cache\CacheItemInterface;

/**
 * Interface CacheItem.
 *
 * Extends the PSR-6 CacheItemInterface to include advanced features like
 * tagging and topic subscriptions for more granular cache management.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheItem extends CacheItemInterface
{
    /**
     * Add tags to this cache item.
     *
     * @param Tag[] $tags an array of tags to associate with the cache item
     *
     * @return static the instance of the cache item for method chaining
     */
    public function tag(array $tags): static;

    /**
     * Get tags associated with this item.
     *
     * @return string[] an array of tags
     */
    public function getTags(): array;

    /**
     * Subscribe to invalidation topics.
     *
     * @param string[] $topics an array of topics to subscribe to
     *
     * @return static the instance of the cache item for method chaining
     */
    public function subscribeTo(array $topics): static;

    /**
     * Get subscribed topics.
     *
     * @return string[] an array of subscribed topics
     */
    public function getTopics(): array;

    /**
     * Returns the expiration timestamp.
     */
    public function getExpiration(): ?\DateTimeInterface;
}
