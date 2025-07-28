<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when cache warming starts.
 */
final class CacheWarmupEvent extends CacheEvent implements StoppableEventInterface
{
    public function __construct(
        private array $data,
        private \DateInterval|int|null $ttl = null,
        array $context = []
    ) {
        parent::__construct('warmup', $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getTtl(): \DateInterval|int|null
    {
        return $this->ttl;
    }

    public function getDataCount(): int
    {
        return count($this->data);
    }
}
