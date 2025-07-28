<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when a cache key is subscribed to topics.
 */
final class TopicSubscriptionEvent extends CacheEvent implements StoppableEventInterface
{
    /**
     * @param string $key the cache key being subscribed
     * @param string[] $topics the topics it was subscribed to
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $key,
        private readonly array $topics,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return $this->topics;
    }
}
