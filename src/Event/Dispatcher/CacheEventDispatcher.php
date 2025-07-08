<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Dispatcher;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 compatible Event Dispatcher implementation.
 */
final class CacheEventDispatcher implements EventDispatcherInterface
{
    private array $listeners = [];
    private ?ListenerProviderInterface $listenerProvider = null;

    public function __construct(?ListenerProviderInterface $listenerProvider = null)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(object $event): object
    {
        $eventClass = get_class($event);

        // Get listeners from provider if available
        if ($this->listenerProvider) {
            $listeners = $this->listenerProvider->getListenersForEvent($event);
        } else {
            $listeners = $this->listeners[$eventClass] ?? [];
        }

        foreach ($listeners as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            if (is_callable($listener)) {
                $listener($event);
            }
        }

        return $event;
    }

    /**
     * Add a listener for a specific event type.
     */
    public function addListener(string $eventType, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventType])) {
            $this->listeners[$eventType] = [];
        }

        $this->listeners[$eventType][] = ['listener' => $listener, 'priority' => $priority];

        // Sort by priority (higher priority first)
        usort($this->listeners[$eventType], fn ($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Remove a listener for a specific event type.
     */
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

    /**
     * Get all listeners for an event type.
     */
    public function getListeners(string $eventType): array
    {
        return array_column($this->listeners[$eventType] ?? [], 'listener');
    }

    /**
     * Check if there are listeners for an event type.
     */
    public function hasListeners(string $eventType): bool
    {
        return !empty($this->listeners[$eventType]);
    }
}
