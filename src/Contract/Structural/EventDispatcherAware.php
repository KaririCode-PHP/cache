<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * EventDispatcherAware interface.
 *
 * This interface defines methods for managing event dispatchers.
 * Event dispatchers enable pub/sub patterns and event-driven
 * cache operations for better integration and monitoring.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface EventDispatcherAware
{
    /**
     * Sets the event dispatcher for the current instance.
     *
     * @param EventDispatcherInterface $eventDispatcher the event dispatcher to set
     *
     * @return EventDispatcherAware returns the current instance to allow method chaining
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): EventDispatcherAware;

    /**
     * Retrieves the current event dispatcher.
     *
     * @return EventDispatcherInterface the current event dispatcher
     */
    public function getEventDispatcher(): EventDispatcherInterface;

    /**
     * Checks if an event dispatcher is currently set.
     *
     * @return bool true if event dispatcher is set, false otherwise
     */
    public function hasEventDispatcher(): bool;
}
