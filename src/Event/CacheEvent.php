<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Base class for all cache events following PSR-14 standards.
 *
 * This abstract class provides common functionality for all cache events
 * including event propagation control, timing information, and context data.
 * It follows the Template Method pattern to ensure consistent behavior
 * across all cache events.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       PSR-14: Event Dispatcher
 */
abstract class CacheEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;
    private readonly float $timestamp;

    /**
     * @param string $key The cache key associated with this event
     * @param array<string, mixed> $context Additional context information
     */
    public function __construct(
        private readonly string $key,
        private readonly array $context = []
    ) {
        $this->timestamp = microtime(true);
    }

    /**
     * Gets the cache key associated with this event.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Gets additional context information for this event.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Gets the timestamp when this event was created.
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Checks if event propagation has been stopped.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Stops the propagation of this event to further listeners.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
