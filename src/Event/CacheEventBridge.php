<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Bridge adapter between PSR-14 and legacy cache event system.
 */
final class CacheEventBridge
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?CacheEventListener $legacyListener = null
    ) {
    }

    public function dispatchCacheHit(string $key, mixed $value, float $retrievalTime = 0.0): void
    {
        $event = new CacheHitEvent($key, $value, $retrievalTime);
        $this->eventDispatcher->dispatch($event);

        // Bridge to legacy listener
        if ($this->legacyListener) {
            $this->legacyListener->onCacheHit($key, $value);
        }
    }

    public function dispatchCacheMiss(string $key, ?callable $callback = null): void
    {
        $event = new CacheMissEvent($key, $callback);
        $this->eventDispatcher->dispatch($event);

        // Bridge to legacy listener
        if ($this->legacyListener) {
            $this->legacyListener->onCacheMiss($key);
        }
    }

    public function dispatchCacheSet(string $key, mixed $value, $ttl = null, array $tags = []): void
    {
        $event = new CacheSetEvent($key, $value, $ttl, $tags);
        $this->eventDispatcher->dispatch($event);

        // Bridge to legacy listener
        if ($this->legacyListener) {
            $this->legacyListener->onCacheSet($key, $value);
        }
    }

    public function dispatchCacheDelete(string $key, bool $existed = false): void
    {
        $event = new CacheDeleteEvent($key, $existed);
        $this->eventDispatcher->dispatch($event);

        // Bridge to legacy listener
        if ($this->legacyListener) {
            $this->legacyListener->onCacheDelete($key);
        }
    }

    public function dispatchCacheClear(?array $tags = null): void
    {
        $event = new CacheClearEvent($tags);
        $this->eventDispatcher->dispatch($event);

        // Bridge to legacy listener
        if ($this->legacyListener) {
            $this->legacyListener->onCacheClear();
        }
    }

    public function dispatchTagInvalidation(array $tags, int $affectedKeys = 0): void
    {
        $event = new TagInvalidationEvent($tags, $affectedKeys);
        $this->eventDispatcher->dispatch($event);
    }

    public function dispatchCacheWarmup(array $data, $ttl = null): void
    {
        $event = new CacheWarmupEvent($data, $ttl);
        $this->eventDispatcher->dispatch($event);
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function setLegacyListener(?CacheEventListener $listener): void
    {
        $this->legacyListener = $listener;
    }
}
