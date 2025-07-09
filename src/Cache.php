<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheKeyValidator as CacheKeyValidatorContract;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\CacheWarmupEvent;
use KaririCode\Cache\Event\Listener\CacheEventListener;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\AdvancedCacheProxy;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main Cache class implementing PSR-16 and advanced features with PSR-14 event dispatching.
 * This class provides a unified interface for caching operations, including
 * automatic key sanitization, sliding expiration, metrics collection, and PSR-14 compatible events.
 */
final class Cache implements CacheContract
{
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private LayeredCacheContract $layeredCache,
        private ?Serializer $serializer = null,
        private ?StampedeProtectorContract $stampedeProtector = null,
        private ?MetricsCollectorContract $metricsCollector = null,
        private ?LoggerInterface $logger = null,
        private ?CacheEventListener $eventListener = null,
        private ?CacheKeyValidatorContract $keyValidator = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private array $config = []
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->stampedeProtector = $stampedeProtector ?? new StampedeProtector();
        $this->metricsCollector = $metricsCollector ?? new MetricsCollector();
        $this->logger = $logger ?? new NullLogger();
        $this->keyValidator = $keyValidator ?? new CacheKeyValidator();
        $this->config = array_merge($this->getDefaultConfig(), $config);

        // Set PSR-14 event dispatcher on event listener if both are available
        if ($this->eventListener && $this->eventDispatcher && method_exists($this->eventListener, 'setEventDispatcher')) {
            $this->eventListener->setEventDispatcher($this->eventDispatcher);
        }
    }

    /**
     * PSR-16 compatible get method with integrated PSR-14 event dispatching.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $this->validateKey($key);
            $startTime = microtime(true);

            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $value = $this->serializer->unserialize($item->get());
                $retrievalTime = microtime(true) - $startTime;

                $this->metricsCollector->recordHit($key, $retrievalTime);

                // Dispatch both legacy and PSR-14 events
                $this->dispatchCacheHit($key, $value, $retrievalTime);

                $this->handleSlidingExpiration($key, $item, null);

                return $value;
            }

            $this->metricsCollector->recordMiss($key);

            // Dispatch both legacy and PSR-14 events
            $this->dispatchCacheMiss($key);

            return $default;
        } catch (\Exception $e) {
            $this->logger->error('Cache get operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Enhanced get method with callback, stampede protection, and PSR-14 event dispatching.
     */
    public function getWithCallback(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed
    {
        $startTime = microtime(true);

        try {
            $this->validateKey($key);

            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $value = $this->serializer->unserialize($item->get());
                $retrievalTime = microtime(true) - $startTime;

                $this->metricsCollector->recordHit($key, $retrievalTime);

                // Dispatch both legacy and PSR-14 events
                $this->dispatchCacheHit($key, $value, $retrievalTime);

                $this->handleSlidingExpiration($key, $item, $ttl);

                return $value;
            }

            $this->metricsCollector->recordMiss($key);

            // Dispatch both legacy and PSR-14 events
            $this->dispatchCacheMiss($key);

            if (null === $callback) {
                return null;
            }

            return $this->stampedeProtector->get($key, function () use ($callback, $key, $ttl, $tags, $startTime) {
                $value = $callback();
                $this->setWithTags($key, $value, $ttl, $tags);
                $this->metricsCollector->recordGeneration($key, microtime(true) - $startTime);

                return $value;
            });
        } catch (\Exception $e) {
            $this->logger->error('Cache getWithCallback operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            if (null !== $callback) {
                try {
                    return $callback();
                } catch (\Exception $callbackException) {
                    throw CacheException::operationFailed('getWithCallback', $key, $callbackException);
                }
            }

            throw CacheException::operationFailed('getWithCallback', $key, $e);
        }
    }

    /**
     * PSR-16 compatible set method with PSR-14 event dispatching.
     */
    public function set(string $key, mixed $value, $ttl = null): bool
    {
        $result = $this->setWithTags($key, $value, $ttl, []);

        if ($result) {
            // Dispatch both legacy and PSR-14 events
            $this->dispatchCacheSet($key, $value, $ttl, []);
        }

        return $result;
    }

    /**
     * Set a value in cache with optional tagging and PSR-14 event dispatching.
     */
    public function setWithTags(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        try {
            $this->validateKey($key);
            $result = $this->setValue($key, $value, $ttl, $tags);

            if ($result) {
                // Dispatch both legacy and PSR-14 events
                $this->dispatchCacheSet($key, $value, $ttl, $tags);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Cache set operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('set', $key, $e);
        }
    }

    /**
     * PSR-16 compatible delete method with PSR-14 event dispatching.
     */
    public function delete(string $key): bool
    {
        try {
            $this->validateKey($key);
            $existed = $this->layeredCache->hasItem($key);
            $result = $this->layeredCache->deleteItem($key);

            if ($result) {
                // Dispatch both legacy and PSR-14 events
                $this->dispatchCacheDelete($key, $existed);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Cache delete operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('delete', $key, $e);
        }
    }

    /**
     * PSR-16 compatible clear method with PSR-14 event dispatching.
     */
    public function clear(): bool
    {
        try {
            $this->metricsCollector->recordClear();
            $result = $this->layeredCache->clear();

            if ($result) {
                // Dispatch both legacy and PSR-14 events
                $this->dispatchCacheClear();
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Cache clear operation failed', [
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('clear', 'all', $e);
        }
    }

    /**
     * PSR-16 methods implementation...
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        $results = [];

        foreach ($keys as $key) {
            // Re-use the existing `get` method to ensure all logic (validation, events) is applied.
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple(iterable $values, $ttl = null): bool
    {
        $values = is_array($values) ? $values : iterator_to_array($values);
        $success = true;

        foreach ($values as $key => $value) {
            // Re-use the existing `set` method.
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        $success = true;

        foreach ($keys as $key) {
            // Re-use the existing `delete` method.
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function has(string $key): bool
    {
        try {
            $this->validateKey($key);

            return $this->layeredCache->hasItem($key);
        } catch (\Exception $e) {
            $this->logger->error('Cache has operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $this->validateKey($key);

        try {
            return $this->layeredCache->increment($key, $step, $initialValue, $ttl);
        } catch (\Exception $e) {
            // Fallback to non-atomic increment
            $current = $this->get($key, $initialValue);
            if (!is_int($current)) {
                $current = $initialValue;
            }
            $newValue = $current + $step;
            $this->set($key, $newValue, $ttl);

            return $newValue;
        }
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $this->validateKey($key);

        try {
            return $this->layeredCache->add($key, $this->serializer->serialize($value), $ttl);
        } catch (\Exception $e) {
            // Fallback implementation
            if (!$this->has($key)) {
                return $this->set($key, $value, $ttl);
            }

            return false;
        }
    }

    public function invalidateTags(array $tags): bool
    {
        try {
            $result = $this->layeredCache->invalidateTags($tags);

            if ($result && $this->eventDispatcher) {
                $event = new TagInvalidationEvent($tags);
                $this->eventDispatcher->dispatch($event);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Cache tag invalidation failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::tagInvalidationFailed($tags, $e);
        }
    }

    public function advanced(): CacheItemPool
    {
        return new AdvancedCacheProxy($this->layeredCache, $this->serializer);
    }

    public function warm(array $data, $ttl = null): void
    {
        // Dispatch PSR-14 warmup event
        if ($this->eventDispatcher) {
            $event = new CacheWarmupEvent($data, $ttl);
            $this->eventDispatcher->dispatch($event);
        }

        $this->setMultiple($data, $ttl);
        $this->logger->info('Cache warmed', ['keys' => array_keys($data)]);
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->layeredCache->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->layeredCache->invalidateTopics($topics);
    }

    /**
     * Set with automatic key sanitization.
     */
    public function setWithAutoSanitization(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        $sanitizedKey = $this->keyValidator->sanitizeKey($key);

        if ($sanitizedKey !== $key) {
            $this->logger->info('Key was sanitized', [
                'original_key' => $key,
                'sanitized_key' => $sanitizedKey,
            ]);
        }

        return $this->setWithTags($sanitizedKey, $value, $ttl, $tags);
    }

    /**
     * Get with automatic key sanitization.
     */
    public function getWithAutoSanitization(string $key, mixed $default = null): mixed
    {
        $sanitizedKey = $this->keyValidator->sanitizeKey($key);

        if ($sanitizedKey !== $key) {
            $this->logger->info('Key was sanitized', [
                'original_key' => $key,
                'sanitized_key' => $sanitizedKey,
            ]);
        }

        return $this->get($sanitizedKey, $default);
    }

    /**
     * Get the event listener instance (for accessing event data).
     */
    public function getEventListener(): ?CacheEventListener
    {
        return $this->eventListener;
    }

    /**
     * Get the key validator instance.
     */
    public function getKeyValidator(): CacheKeyValidatorContract
    {
        return $this->keyValidator;
    }

    /**
     * Set PSR-14 event dispatcher.
     */
    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;

        // Also set it on the event listener if available
        if ($this->eventListener && method_exists($this->eventListener, 'setEventDispatcher')) {
            $this->eventListener->setEventDispatcher($eventDispatcher);
        }
    }

    /**
     * Get PSR-14 event dispatcher.
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Get cache statistics and metrics including PSR-14 event data.
     */
    public function getMetrics(): array
    {
        $metrics = $this->metricsCollector->getMetrics();

        if ($this->eventListener && method_exists($this->eventListener, 'getStats')) {
            $metrics['events'] = $this->eventListener->getStats();
        }

        return $metrics;
    }

    /**
     * Dispatch cache hit event to both legacy and PSR-14 systems.
     */
    private function dispatchCacheHit(string $key, mixed $value, float $retrievalTime = 0.0): void
    {
        // Legacy event system
        if ($this->eventListener) {
            $this->eventListener->onCacheHit($key, $value);
        }

        // PSR-14 event system
        if ($this->eventDispatcher) {
            $event = new CacheHitEvent($key, $value, $retrievalTime);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache miss event to both legacy and PSR-14 systems.
     */
    private function dispatchCacheMiss(string $key): void
    {
        // Legacy event system
        if ($this->eventListener) {
            $this->eventListener->onCacheMiss($key);
        }

        // PSR-14 event system
        if ($this->eventDispatcher) {
            $event = new CacheMissEvent($key);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache set event to both legacy and PSR-14 systems.
     */
    private function dispatchCacheSet(string $key, mixed $value, $ttl = null, array $tags = []): void
    {
        // Legacy event system
        if ($this->eventListener) {
            $this->eventListener->onCacheSet($key, $value);
        }

        // PSR-14 event system
        if ($this->eventDispatcher) {
            $event = new CacheSetEvent($key, $value, $ttl, $tags);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache delete event to both legacy and PSR-14 systems.
     */
    private function dispatchCacheDelete(string $key, bool $existed = false): void
    {
        // Legacy event system
        if ($this->eventListener) {
            $this->eventListener->onCacheDelete($key);
        }

        // PSR-14 event system
        if ($this->eventDispatcher) {
            $event = new CacheDeleteEvent($key, $existed);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache clear event to both legacy and PSR-14 systems.
     */
    private function dispatchCacheClear(?array $tags = null): void
    {
        // Legacy event system
        if ($this->eventListener) {
            $this->eventListener->onCacheClear();
        }

        // PSR-14 event system
        if ($this->eventDispatcher) {
            $event = new CacheClearEvent($tags);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Centralized key validation using the injected validator.
     */
    private function validateKey(string $key): void
    {
        if (!$this->keyValidator->validateKey($key)) {
            $errors = $this->keyValidator->getValidationErrors($key);
            $error = reset($errors);

            match (true) {
                str_contains($error, 'empty') => throw InvalidArgumentException::emptyKey(),
                str_contains($error, 'too long') => throw InvalidArgumentException::keyTooLong($key),
                str_contains($error, 'invalid characters') => throw InvalidArgumentException::invalidKeyFormat($key),
                str_contains($error, 'reserved') => throw InvalidArgumentException::invalidKeyFormat($key),
                default => throw new InvalidArgumentException($error)
            };
        }
    }

    private function setValue(string $key, mixed $value, $ttl, array $tags): bool
    {
        $serializedValue = $this->serializer->serialize($value);
        $expiration = $this->calculateExpiration($ttl);

        $item = new CacheItem($key, $serializedValue, true);
        $item->expiresAt($expiration);

        if (!empty($tags)) {
            $item->tag($tags);
        }

        return $this->layeredCache->save($item);
    }

    private function handleSlidingExpiration(string $key, Contract\CacheItem $item, $ttl): void
    {
        if (($this->config['sliding_expiration'] ?? false) && null !== $ttl) {
            $newExpiration = $this->calculateExpiration($ttl);
            $item->expiresAt($newExpiration);
            $this->layeredCache->save($item);
        }
    }

    private function calculateExpiration($ttl): ?\DateTimeInterface
    {
        if (null === $ttl) {
            $ttl = $this->config['default_ttl'] ?? self::DEFAULT_TTL;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return null; // A TTL of 0 or less means it expires immediately or is already expired.
            }

            return (new \DateTimeImmutable())->add(new \DateInterval('PT' . $ttl . 'S'));
        }

        return null;
    }

    private function getDefaultConfig(): array
    {
        return [
            'default_ttl' => self::DEFAULT_TTL,
            'sliding_expiration' => false,
            'stampede_protection' => true,
            'metrics_enabled' => true,
        ];
    }
}
