<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when tags are invalidated.
 */
final class TagInvalidationEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        private array $tags,
        private int $affectedKeys = 0,
        array $context = []
    ) {
        parent::__construct('tags:' . implode(',', $tags), $context);
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getAffectedKeys(): int
    {
        return $this->affectedKeys;
    }
}
