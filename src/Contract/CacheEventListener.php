<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use Psr\EventDispatcher\EventDispatcherInterface;

interface CacheEventListener
{
    public function onCacheHit(string $key, mixed $value): void;

    public function onCacheMiss(string $key): void;

    public function onCacheSet(string $key, mixed $value): void;

    public function onCacheDelete(string $key): void;

    public function onCacheClear(): void;

    /**
     * Returns statistics about the events that have occurred.
     *
     * @return array<string, int> an associative array of event types to their counts
     */
    public function getStats(): array;

    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void;

    public function getEventDispatcher(): EventDispatcherInterface;
}
