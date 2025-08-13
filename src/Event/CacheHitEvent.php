<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when a cache hit occurs.
 *
 * This event provides comprehensive information about successful cache
 * retrievals including timing, value information, and performance metrics.
 */
class CacheHitEvent extends CacheEvent
{
    /**
     * @param string $key The cache key that resulted in a hit
     * @param mixed $value The value retrieved from cache
     * @param float $retrievalTime Time taken to retrieve the value (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        string $key,
        private readonly mixed $value,
        private readonly float $retrievalTime = 0.0,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    /**
     * Gets the value that was retrieved from cache.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Gets the time taken to retrieve the value from cache.
     */
    public function getDuration(): float
    {
        return $this->retrievalTime;
    }

    /**
     * Gets the type of the cached value.
     */
    public function getValueType(): string
    {
        return get_debug_type($this->value);
    }

    /**
     * Gets the serialized size of the cached value.
     */
    public function getValueSize(): int
    {
        try {
            return strlen(serialize($this->value));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Gets the retrieval time in milliseconds for easier reading.
     */
    public function getRetrievalTimeMs(): float
    {
        return round($this->retrievalTime * 1000, 2);
    }
}
