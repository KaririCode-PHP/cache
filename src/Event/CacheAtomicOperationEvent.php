<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event dispatched when atomic operations are performed.
 *
 * This event provides information about atomic cache operations
 * like increment, decrement, add, and compare-and-swap.
 */
final class CacheAtomicOperationEvent extends CacheEvent
{
    /**
     * @param string $operation The atomic operation performed (increment, decrement, add, compareAndSwap)
     * @param string $key The cache key involved
     * @param mixed $result The result of the operation
     * @param float $duration Time taken to perform the operation (in seconds)
     * @param array<string, mixed> $data Additional operation-specific data
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly string $operation,
        string $key,
        private readonly mixed $result,
        private readonly float $duration = 0.0,
        private readonly array $data = [],
        array $context = []
    ) {
        parent::__construct($key, array_merge($context, ['atomic_operation' => $operation]));
    }

    /**
     * Gets the atomic operation that was performed.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Gets the result of the atomic operation.
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Gets the time taken to perform the operation.
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
     * Gets additional operation-specific data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Checks if the atomic operation was successful.
     */
    public function wasSuccessful(): bool
    {
        // For boolean operations (add, compareAndSwap), check the result directly
        if (is_bool($this->result)) {
            return $this->result;
        }

        // For numeric operations (increment, decrement), success is implied if we have a result
        return null !== $this->result;
    }
}
