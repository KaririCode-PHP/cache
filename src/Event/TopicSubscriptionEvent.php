<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when a cache key is subscribed to topics.
 *
 * This event provides information about topic subscription operations
 * including timing and the topics being subscribed to.
 */
final class TopicSubscriptionEvent extends CacheEvent
{
    /**
     * @param string $key The cache key being subscribed
     * @param array<string> $topics The topics being subscribed to
     * @param float $duration Time taken to perform the subscription (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        string $key,
        private readonly array $topics,
        private readonly float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    /**
     * Gets the topics that the key was subscribed to.
     *
     * @return array<string>
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * Gets the time taken to perform the subscription.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Gets the duration in milliseconds for easier reading.
     */
    public function getDurationMs(): float
    {
        return round($this->duration * 1000, 2);
    }

    /**
     * Gets the number of topics subscribed to.
     */
    public function getTopicCount(): int
    {
        return count($this->topics);
    }
}
