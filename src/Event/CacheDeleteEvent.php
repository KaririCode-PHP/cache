<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when a cache item is deleted.
 */
final class CacheDeleteEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        string $key,
        private bool $existed = false,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    public function existed(): bool
    {
        return $this->existed;
    }
}
