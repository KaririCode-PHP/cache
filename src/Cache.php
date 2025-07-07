<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheKeyValidator as CacheKeyValidatorContract;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced Cache implementation with integrated event system and centralized validation.
 *
 * This refactored version properly integrates:
 * - CacheEventListener for comprehensive event tracking
 * - CacheKeyValidator for centralized key validation
 * - Improved error handling and logging
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
        private array $config = []
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->stampedeProtector = $stampedeProtector ?? new StampedeProtector();
        $this->metricsCollector = $metricsCollector ?? new MetricsCollector();
        $this->logger = $logger ?? new NullLogger();
        $this->eventListener = $eventListener;
        $this->keyValidator = $keyValidator ?? new CacheKeyValidator();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * PSR-16 compatible get method with integrated event dispatching.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $this->validateKey($key);

            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $value = $this->serializer->unserialize($item->get());
                $this->metricsCollector->recordHit($key, 0);

                // Dispatch cache hit event
                $this->eventListener?->onCacheHit($key, $value);

                $this->handleSlidingExpiration($key, $item, null);

                return $value;
            }

            $this->metricsCollector->recordMiss($key);

            // Dispatch cache miss event
            $this->eventListener?->onCacheMiss($key);

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
     * Enhanced get method with callback, stampede protection, and event dispatching.
     */
    public function getWithCallback(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed
    {
        $startTime = microtime(true);

        try {
            $this->validateKey($key);

            // Try to get from cache first
            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $value = $this->serializer->unserialize($item->get());
                $this->metricsCollector->recordHit($key, microtime(true) - $startTime);

                // Dispatch cache hit event
                $this->eventListener?->onCacheHit($key, $value);

                $this->handleSlidingExpiration($key, $item, $ttl);

                return $value;
            }

            // Cache miss - record metrics and dispatch event
            $this->metricsCollector->recordMiss($key);
            $this->eventListener?->onCacheMiss($key);

            // If no callback provided, return null
            if (null === $callback) {
                return null;
            }

            // Use stampede protection to generate value
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

            // If callback provided, try to execute it as fallback
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
     * PSR-16 compatible set method with event dispatching.
     */
    public function set(string $key, mixed $value, $ttl = null): bool
    {
        $result = $this->setWithTags($key, $value, $ttl, []);

        if ($result) {
            // Dispatch cache set event
            $this->eventListener?->onCacheSet($key, $value);
        }

        return $result;
    }

    /**
     * Set a value in cache with optional tagging and event dispatching.
     */
    public function setWithTags(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        try {
            $this->validateKey($key);

            $result = $this->setValue($key, $value, $ttl, $tags);

            if ($result) {
                // Dispatch cache set event
                $this->eventListener?->onCacheSet($key, $value);
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
     * PSR-16 compatible delete method with event dispatching.
     */
    public function delete(string $key): bool
    {
        try {
            $this->validateKey($key);

            $result = $this->layeredCache->deleteItem($key);

            if ($result) {
                // Dispatch cache delete event
                $this->eventListener?->onCacheDelete($key);
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
     * PSR-16 compatible clear method with event dispatching.
     */
    public function clear(): bool
    {
        try {
            $this->metricsCollector->recordClear();

            $result = $this->layeredCache->clear();

            if ($result) {
                // Dispatch cache clear event
                $this->eventListener?->onCacheClear();
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
    public function getKeyValidator(): CacheKeyValidator
    {
        return $this->keyValidator;
    }

    /**
     * Get cache statistics and metrics including event data.
     */
    public function getMetrics(): array
    {
        $metrics = $this->metricsCollector->getMetrics();

        // Add event statistics if available
        if ($this->eventListener && method_exists($this->eventListener, 'getStats')) {
            $metrics['events'] = $this->eventListener->getStats();
        }

        return $metrics;
    }

    // ... (outros métodos PSR-16 permanecem inalterados)

    /**
     * Centralized key validation using the injected validator.
     */
    private function validateKey(string $key): void
    {
        if (!$this->keyValidator->validateKey($key)) {
            $errors = $this->keyValidator->getValidationErrors($key);
            $error = reset($errors);

            // Map validation errors to specific exceptions
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

    private function handleSlidingExpiration(string $key, CacheItem $item, $ttl): void
    {
        if ($this->config['sliding_expiration'] && null !== $ttl) {
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
            return (new \DateTime())->add($ttl);
        }

        if (is_int($ttl)) {
            return new \DateTime('+' . $ttl . ' seconds');
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

    // PSR-16 methods implementation...
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
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
            return $this->layeredCache->invalidateTags($tags);
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
        foreach ($data as $key => $value) {
            $this->set($key, $value, $ttl);
        }
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
}
