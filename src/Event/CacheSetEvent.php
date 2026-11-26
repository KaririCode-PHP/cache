<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when a value is set in cache.
 *
 * This event provides comprehensive information about cache storage operations
 * including TTL, tags, timing, and value characteristics.
 */
final class CacheSetEvent extends CacheEvent
{
    /**
     * @param string $key The cache key being set
     * @param mixed $value The value being stored
     * @param \DateInterval|int|null $ttl The time-to-live for the cached item
     * @param float $duration Time taken to store the value (in seconds)
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        string $key,
        private readonly mixed $value,
        private readonly \DateInterval|int|null $ttl = null,
        private readonly float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    /**
     * Gets the value being stored in cache.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Gets the TTL for the cached item.
     */
    public function getTtl(): \DateInterval|int|null
    {
        return $this->ttl;
    }

    /**
     * Gets the time taken to store the value.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Gets the type of the stored value.
     */
    public function getValueType(): string
    {
        return get_debug_type($this->value);
    }

    /**
     * Gets the serialized size of the stored value.
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
     * Gets the duration in milliseconds for easier reading.
     */
    public function getDurationMs(): float
    {
        return round($this->duration * 1000, 2);
    }

    /**
     * Gets TTL in seconds for consistent reporting.
     */
    public function getTtlSeconds(): ?int
    {
        if (null === $this->ttl) {
            return null;
        }

        if (is_int($this->ttl)) {
            return $this->ttl;
        }

        if ($this->ttl instanceof \DateInterval) {
            // Convert DateInterval to seconds (approximation)
            $reference = new \DateTimeImmutable();
            $endTime = $reference->add($this->ttl);

            return $endTime->getTimestamp() - $reference->getTimestamp();
        }

        return null;
    }

    /**
     * Checks if tags were associated with this cache set operation.
     */
    public function hasTags(): bool
    {
        return !empty($this->getContext()['tags'] ?? []);
    }

    /**
     * Gets the tags associated with this cache set operation.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->getContext()['tags'] ?? [];
    }
}
