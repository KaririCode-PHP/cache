<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when a cache hit occurs.
 */
final class CacheHitEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        string $key,
        private mixed $value,
        private float $retrievalTime = 0.0,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getRetrievalTime(): float
    {
        return $this->retrievalTime;
    }

    public function getValueType(): string
    {
        return gettype($this->value);
    }

    public function getValueSize(): int
    {
        return strlen(serialize($this->value));
    }
}
