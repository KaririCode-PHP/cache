<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when a cache miss occurs.
 *
 * This event indicates that a requested cache key was not found or had expired.
 * It includes information about whether a callback was available to generate
 * the missing value.
 */
final class CacheMissEvent extends CacheEvent
{
    /**
     * @param string $key The cache key that resulted in a miss
     * @param bool $hasCallback Whether a callback was available to generate the value
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        string $key,
        private readonly bool $hasCallback = false,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    /**
     * Checks if a callback was available to generate the missing value.
     */
    public function hasCallback(): bool
    {
        return $this->hasCallback;
    }

    /**
     * Checks if this was a cold miss (no callback available).
     */
    public function isColdMiss(): bool
    {
        return !$this->hasCallback;
    }
}
