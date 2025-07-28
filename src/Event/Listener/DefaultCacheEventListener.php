<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener as CacheEventListenerContract;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Enhanced DefaultCacheEventListener with configurable max events.
 */
final class DefaultCacheEventListener implements CacheEventListenerContract
{
    /**
     * @var array<int, array{type: string, key: string, timestamp: float, data: array<mixed>}>
     */
    private array $events = [];

    private ?EventDispatcherInterface $eventDispatcher = null;

    public function __construct(private int $maxEvents = 1000)
    {
    }

    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        $this->recordEvent('hit', $key, ['value_type' => gettype($value)]);

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheHitEvent($key, $value));
        }
    }

    public function onCacheMiss(string $key): void
    {
        $this->recordEvent('miss', $key);

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheMissEvent($key));
        }
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $this->recordEvent('set', $key, [
            'value_type' => gettype($value),
            'value_size' => is_string($value) ? strlen($value) : null,
        ]);

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheSetEvent($key, $value));
        }
    }

    public function onCacheDelete(string $key): void
    {
        $this->recordEvent('delete', $key);

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheDeleteEvent($key));
        }
    }

    public function onCacheClear(): void
    {
        $this->recordEvent('clear', 'all');

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheClearEvent());
        }
    }

    /**
     * @return array<int, array{type: string, key: string, timestamp: float, data: array<mixed>}>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<int, array{type: string, key: string, timestamp: float, data: array<mixed>}>
     */
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

        if (count($this->events) > $this->maxEvents) {
            array_shift($this->events);
        }
    }
}
