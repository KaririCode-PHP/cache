<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event

/**
 * Analytics-specific event for PSR-14 compatibility.
 */
final class CacheAnalyticsEvent
{
    private bool $propagationStopped = false;

    public function __construct(
        private string $operation,
        private string $key,
        private array $analytics
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

    public function getAnalytics(): array
    {
        return $this->analytics;
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
