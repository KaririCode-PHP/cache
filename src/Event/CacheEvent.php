<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * PSR-14 compatible cache events.
 */
abstract class CacheEvent
{
    private bool $propagationStopped = false;
    private float $timestamp;

    public function __construct(
        private string $key,
        private array $context = []
    ) {
        $this->timestamp = microtime(true);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
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
