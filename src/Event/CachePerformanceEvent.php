<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched for performance monitoring and analysis.
 *
 * This event provides detailed performance information about cache
 * operations for monitoring and optimization purposes.
 */
final class CachePerformanceEvent extends CacheEvent
{
    /**
     * @param string $operation The operation being performed
     * @param string $key The cache key involved in the operation
     * @param float $processingTime Time taken to perform the operation (in seconds)
     * @param int $valueSize Size of the value in bytes
     * @param array<string, mixed> $additionalData Additional performance data
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly string $operation,
        string $key,
        private readonly float $processingTime,
        private readonly int $valueSize = 0,
        private readonly array $additionalData = [],
        array $context = []
    ) {
        parent::__construct($key, array_merge($context, ['operation' => $operation]));
    }

    /**
     * Gets the operation that was performed.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Gets the time taken to perform the operation.
     */
    public function getProcessingTime(): float
    {
        return $this->processingTime;
    }

    /**
     * Gets the processing time in milliseconds for easier reading.
     */
    public function getProcessingTimeMs(): float
    {
        return round($this->processingTime * 1000, 2);
    }

    /**
     * Gets the size of the value involved in the operation.
     */
    public function getValueSize(): int
    {
        return $this->valueSize;
    }

    /**
     * Gets additional performance data.
     *
     * @return array<string, mixed>
     */
    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }

    /**
     * Checks if the operation is considered slow based on a threshold.
     */
    public function isSlowOperation(float $thresholdSeconds = 0.1): bool
    {
        return $this->processingTime > $thresholdSeconds;
    }

    /**
     * Gets the throughput in bytes per second.
     */
    public function getThroughput(): float
    {
        if ($this->processingTime <= 0 || $this->valueSize <= 0) {
            return 0.0;
        }

        return $this->valueSize / $this->processingTime;
    }
}
