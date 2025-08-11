<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when tags are invalidated.
 *
 * This event provides comprehensive information about tag invalidation
 * operations including affected keys count and timing information.
 */
final class TagInvalidationEvent extends CacheEvent
{
    /**
     * @param array<string> $tags The tags being invalidated
     * @param float $duration Time taken to invalidate the tags (in seconds)
     * @param int $affectedKeys Number of cache keys affected by the invalidation
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly array $tags,
        private readonly float $duration = 0.0,
        private readonly int $affectedKeys = 0,
        array $context = []
    ) {
        parent::__construct('tags:' . implode(',', $tags), $context);
    }

    /**
     * Gets the tags that were invalidated.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Gets the time taken to invalidate the tags.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Gets the number of cache keys affected by the invalidation.
     */
    public function getAffectedKeys(): int
    {
        return $this->affectedKeys;
    }

    /**
     * Gets the duration in milliseconds for easier reading.
     */
    public function getDurationMs(): float
    {
        return round($this->duration * 1000, 2);
    }

    /**
     * Gets the number of tags invalidated.
     */
    public function getTagCount(): int
    {
        return count($this->tags);
    }
}
