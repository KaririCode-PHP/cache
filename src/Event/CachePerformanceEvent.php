<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * PSR-14 compatible performance event.
 */
final class CachePerformanceEvent
{
    private bool $propagationStopped = false;

    public function __construct(
        private string $operation,
        private string $key,
        private float $processingTime,
        private int $valueSize = 0,
        private array $additionalData = []
    ) {
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getProcessingTime(): float
    {
        return $this->processingTime;
    }

    public function getValueSize(): int
    {
        return $this->valueSize;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
