<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when the cache is cleared.
 *
 * This event provides information about cache clearing operations including
 * whether it was a tagged clear operation and timing information.
 */
final class CacheClearEvent extends CacheEvent
{
    /**
     * @param array<string>|null $tags Optional tags for selective clearing
     * @param float $duration Time taken to clear the cache (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly ?array $tags = null,
        private readonly float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct('*', $context);
    }

    /**
     * Gets the tags used for selective clearing.
     *
     * @return array<string>|null
     */
    public function getTags(): ?array
    {
        return $this->tags;
    }

    /**
     * Checks if this was a tagged clear operation.
     */
    public function isTaggedClear(): bool
    {
        return null !== $this->tags;
    }

    /**
     * Gets the time taken to clear the cache.
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
}
