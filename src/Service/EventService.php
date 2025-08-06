<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Event\CacheAtomicOperationEvent;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service dedicated to dispatching cache-related events.
 * It acts as a central hub for all observable cache actions.
 */
final class EventService
{
    private bool $eventsEnabled = true;

    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function dispatchCacheHit(string $key, mixed $value, float $duration = 0.0): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $this->dispatch(new CacheHitEvent($key, $value, $duration));
        $this->logger->debug('Cache hit', ['key' => $key]);
    }

    public function dispatchCacheMiss(string $key): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $this->dispatch(new CacheMissEvent($key));
        $this->logger->debug('Cache miss', ['key' => $key]);
    }

    public function dispatchCacheSet(string $key, mixed $value, ?int $ttl, float $duration = 0.0): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $this->dispatch(new CacheSetEvent($key, $value, $ttl, $duration));
        $this->logger->debug('Cache set', ['key' => $key, 'ttl' => $ttl]);
    }

    public function dispatchCacheDelete(string $key, bool $success, float $duration = 0.0): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $this->dispatch(new CacheDeleteEvent($key, $success, $duration));
        $this->logger->debug('Cache delete', ['key' => $key, 'success' => $success]);
    }

    public function dispatchCacheClear(float $duration = 0.0): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $this->dispatch(new CacheClearEvent(null, $duration));
        $this->logger->info('Cache cleared');
    }

    public function dispatchTagInvalidation(iterable $tags, int $affectedKeys, float $duration = 0.0): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $tagStrings = is_array($tags) ? $tags : iterator_to_array($tags);
        $this->dispatch(new TagInvalidationEvent($tagStrings, $duration, $affectedKeys));
        $this->logger->info('Tag invalidation', ['tags' => $tagStrings, 'affected_keys' => $affectedKeys]);
    }

    public function dispatchAtomicOperation(string $operation, string $key, mixed $result, float $duration = 0.0, array $data = []): void
    {
        if (!$this->shouldDispatch()) {
            return;
        }
        $this->dispatch(new CacheAtomicOperationEvent($operation, $key, $result, $duration, $data));
        $this->logger->debug('Atomic operation performed', ['operation' => $operation, 'key' => $key]);
    }

    private function shouldDispatch(): bool
    {
        return $this->eventsEnabled && null !== $this->dispatcher;
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher) {
            $this->dispatcher->dispatch($event);
        }
    }
}
