<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener as CacheEventListenerContract;

/**
 * Event listener for cache operations with logging and metrics.
 */
final class CacheEventListener implements CacheEventListenerContract
{
    private array $events = [];
    private int $maxEvents = 1000;

    public function onCacheHit(string $key, mixed $value): void
    {
        $this->recordEvent('hit', $key, ['value_type' => gettype($value)]);
    }

    public function onCacheMiss(string $key): void
    {
        $this->recordEvent('miss', $key);
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $this->recordEvent('set', $key, [
            'value_type' => gettype($value),
            'value_size' => is_string($value) ? strlen($value) : null,
        ]);
    }

    public function onCacheDelete(string $key): void
    {
        $this->recordEvent('delete', $key);
    }

    public function onCacheClear(): void
    {
        $this->recordEvent('clear', 'all');
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getEventsByType(string $type): array
    {
        return array_filter($this->events, fn ($event) => $event['type'] === $type);
    }

    public function clearEvents(): void
    {
        $this->events = [];
    }

    public function getStats(): array
    {
        $stats = [];
        foreach ($this->events as $event) {
            $type = $event['type'];
            $stats[$type] = ($stats[$type] ?? 0) + 1;
        }

        return $stats;
    }

    private function recordEvent(string $type, string $key, array $data = []): void
    {
        $this->events[] = [
            'type' => $type,
            'key' => $key,
            'timestamp' => microtime(true),
            'data' => $data,
        ];

        // Keep only the last N events to prevent memory issues
        if (count($this->events) > $this->maxEvents) {
            array_shift($this->events);
        }
    }
}
