<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when cache warming operation is performed.
 *
 * This event provides comprehensive information about cache warming
 * operations including success/failure counts and timing information.
 */
final class CacheWarmupEvent extends CacheEvent
{
    /**
     * @param array<string> $keys The cache keys being warmed up
     * @param int $successCount Number of items successfully warmed up
     * @param float $duration Time taken to perform the warmup (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly array $keys,
        private readonly int $successCount,
        private readonly float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct('warmup:' . count($keys) . '_keys', $context);
    }

    /**
     * Gets the cache keys that were warmed up.
     *
     * @return array<string>
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * Gets the number of items successfully warmed up.
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * Gets the number of items that failed to warm up.
     */
    public function getFailureCount(): int
    {
        return count($this->keys) - $this->successCount;
    }

    /**
     * Gets the total number of items attempted to warm up.
     */
    public function getTotalCount(): int
    {
        return count($this->keys);
    }

    /**
     * Gets the time taken to perform the warmup.
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
     * Gets the success rate as a percentage.
     */
    public function getSuccessRate(): float
    {
        $total = $this->getTotalCount();
        if (0 === $total) {
            return 100.0;
        }

        return round(($this->successCount / $total) * 100, 2);
    }

    /**
     * Checks if the warmup was completely successful.
     */
    public function wasCompletelySuccessful(): bool
    {
        return $this->successCount === count($this->keys);
    }
}
