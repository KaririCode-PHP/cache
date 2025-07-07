<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when a cache miss occurs.
 */
final class CacheMissEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        string $key,
        private ?callable $callback = null,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    public function hasCallback(): bool
    {
        return null !== $this->callback;
    }

    public function getCallback(): ?callable
    {
        return $this->callback;
    }
}