<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when cache topics are invalidated.
 *
 * This event provides comprehensive information about topic invalidation
 * operations including affected keys count and timing information.
 */
final class TopicInvalidationEvent extends CacheEvent
{
    /**
     * @param array<string> $topics The topics being invalidated
     * @param int $affectedKeys Number of cache keys affected by the invalidation
     * @param float $duration Time taken to invalidate the topics (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly array $topics,
        private readonly int $affectedKeys = 0,
        private readonly float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct('topics:' . implode(',', $topics), $context);
    }

    /**
     * Gets the topics that were invalidated.
     *
     * @return array<string>
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * Gets the number of cache keys affected by the invalidation.
     */
    public function getAffectedKeysCount(): int
    {
        return $this->affectedKeys;
    }

    /**
     * Gets the time taken to invalidate the topics.
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
     * Gets the number of topics invalidated.
     */
    public function getTopicCount(): int
    {
        return count($this->topics);
    }
}
