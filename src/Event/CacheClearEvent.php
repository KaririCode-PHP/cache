<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when the cache is cleared.
 */
final class CacheClearEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        private ?array $tags = null,
        array $context = []
    ) {
        parent::__construct('*', $context);
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function isTaggedClear(): bool
    {
        return null !== $this->tags;
    }
}
