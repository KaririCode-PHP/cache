<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
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
 * It acts as a central hub for all observable cache actions, with centralized
 * logic for logging and dispatching to adhere to DRY and SRP principles.
 */
final class EventService
{
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventServiceConfig $config = new EventServiceConfig()
    ) {
    }

    public function dispatchCacheHit(string $key, mixed $value, float $duration = 0.0): void
    {
        $this->dispatchEvent(new CacheHitEvent($key, $value, $duration), 'Cache hit', ['key' => $key]);
    }

    public function dispatchCacheMiss(string $key): void
    {
        $this->dispatchEvent(new CacheMissEvent($key), 'Cache miss', ['key' => $key]);
    }

    public function dispatchCacheSet(string $key, mixed $value, ?int $ttl, float $duration = 0.0): void
    {
        $this->dispatchEvent(new CacheSetEvent($key, $value, $ttl, $duration), 'Cache set', ['key' => $key, 'ttl' => $ttl]);
    }

    public function dispatchCacheDelete(string $key, bool $success, float $duration = 0.0): void
    {
        $this->dispatchEvent(new CacheDeleteEvent($key, $success, $duration), 'Cache delete', ['key' => $key, 'success' => $success]);
    }

    public function dispatchCacheClear(float $duration = 0.0): void
    {
        $this->dispatchEvent(new CacheClearEvent(null, $duration), 'Cache cleared', [], 'info');
    }

    public function dispatchTagInvalidation(iterable $tags, int $affectedKeys, float $duration = 0.0): void
    {
        $tagStrings = is_array($tags) ? $tags : iterator_to_array($tags);
        $context = ['tags' => $tagStrings, 'affected_keys' => $affectedKeys];
        $this->dispatchEvent(new TagInvalidationEvent($tagStrings, $duration, $affectedKeys), 'Tag invalidation', $context, 'info');
    }

    public function dispatchAtomicOperation(string $operation, string $key, mixed $result, float $duration = 0.0, array $data = []): void
    {
        $context = ['operation' => $operation, 'key' => $key];
        $this->dispatchEvent(new CacheAtomicOperationEvent($operation, $key, $result, $duration, $data), 'Atomic operation performed', $context);
    }

    /**
     * Centralized method to handle logging and dispatching of any event.
     */
    private function dispatchEvent(object $event, string $logMessage, array $logContext, string $logLevel = 'debug'): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $this->log($logLevel, $logMessage, $logContext); // Log sempre acontece primeiro

        if (null !== $this->dispatcher) {
            try {
                $this->dispatcher->dispatch($event);
            } catch (\Throwable $e) {
                if ($this->config->logOnDispatchError) {
                    $this->log('error', 'Event dispatcher failed.', [
                        'event' => get_class($event),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->config->enabled) {
            return;
        }
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
        }
    }
}
