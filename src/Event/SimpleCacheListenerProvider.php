<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Simple listener provider implementation.
 */
final class SimpleCacheListenerProvider implements ListenerProviderInterface
{
    private array $listeners = [];

    public function getListenersForEvent(object $event): iterable
    {
        $eventClass = get_class($event);

        // Return listeners for exact class match
        if (isset($this->listeners[$eventClass])) {
            foreach ($this->listeners[$eventClass] as $listener) {
                yield $listener['listener'];
            }
        }

        // Return listeners for parent classes and interfaces
        foreach (class_parents($event) as $parentClass) {
            if (isset($this->listeners[$parentClass])) {
                foreach ($this->listeners[$parentClass] as $listener) {
                    yield $listener['listener'];
                }
            }
        }

        foreach (class_implements($event) as $interface) {
            if (isset($this->listeners[$interface])) {
                foreach ($this->listeners[$interface] as $listener) {
                    yield $listener['listener'];
                }
            }
        }
    }

    public function addListener(string $eventType, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventType])) {
            $this->listeners[$eventType] = [];
        }

        $this->listeners[$eventType][] = ['listener' => $listener, 'priority' => $priority];

        // Sort by priority (higher priority first)
        usort($this->listeners[$eventType], fn ($a, $b) => $b['priority'] <=> $a['priority']);
    }

    public function removeListener(string $eventType, callable $listener): void
    {
        if (!isset($this->listeners[$eventType])) {
            return;
        }

        $this->listeners[$eventType] = array_filter(
            $this->listeners[$eventType],
            fn ($item) => $item['listener'] !== $listener
        );
    }
}
