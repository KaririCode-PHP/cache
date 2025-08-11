<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when a cache item is deleted.
 *
 * This event provides information about cache deletion operations including
 * whether the key existed and timing information.
 */
final class CacheDeleteEvent extends CacheEvent
{
    /**
     * @param string $key The cache key being deleted
     * @param bool $existed Whether the key existed before deletion
     * @param float $duration Time taken to delete the item (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        string $key,
        private readonly bool $existed = false,
        private readonly float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    /**
     * Checks if the key existed before deletion.
     */
    public function isSuccess(): bool
    {
        return $this->existed;
    }

    /**
     * Gets the time taken to delete the item.
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
