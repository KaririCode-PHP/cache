<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when a value is set in cache.
 */
final class CacheSetEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        string $key,
        private mixed $value,
        private \DateInterval|int|null $ttl = null,
        private array $tags = [],
        private float $duration = 0.0,
        array $context = []
    ) {
        parent::__construct($key, $context);
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getTtl(): \DateInterval|int|null
    {
        return $this->ttl;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getValueType(): string
    {
        return gettype($this->value);
    }

    public function getValueSize(): int
    {
        return strlen(serialize($this->value));
    }

    public function getDuration(): float
    {
        return $this->duration;
    }
}
