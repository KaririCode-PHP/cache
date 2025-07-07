<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced Cache implementation with advanced features and excellent DX.
 *
 * This cache implementation provides:
 * - Hybrid API (PSR-16 simple + PSR-6 advanced)
 * - Native stampede protection
 * - Intelligent tagging
 * - Atomic operations
 * - Event-driven invalidation
 * - Comprehensive metrics
 * - Sliding expiration
 * - Cache warming capabilities
 */
final class Cache implements CacheContract
{
    private const DEFAULT_TTL = 3600;
    private const KEY_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';

    public function __construct(
        private LayeredCacheContract $layeredCache,
        private ?Serializer $serializer = null,
        private ?StampedeProtectorContract $stampedeProtector = null,
        private ?MetricsCollectorContract $metricsCollector = null,
        private ?LoggerInterface $logger = null,
        private array $config = []
    ) {
        $this->layeredCache = $layeredCache;
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->stampedeProtector = $stampedeProtector ?? new StampedeProtector();
        $this->metricsCollector = $metricsCollector ?? new MetricsCollector();
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Primary cache method with built-in stampede protection.
     *
     * This method encapsulates the "get or create" pattern with automatic
     * stampede protection. If the key exists, returns the value. If not,
     * executes the callback to generate the value, stores it, and returns it.
     *
     * @param string $key The cache key
     * @param callable $callback Function to generate the value if not cached
     * @param \DateInterval|int|null $ttl Time to live
     * @param array $tags Tags for invalidation grouping
     *
     * @throws InvalidArgumentException If key is invalid
     * @throws CacheException If cache operation fails
     *
     * @return mixed The cached or generated value
     */
    public function get(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed
    {
        $startTime = microtime(true);

        try {
            $this->validateKey($key);

            // Try to get from cache first
            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $value = $this->serializer->unserialize($item->get());
                $this->metricsCollector->recordHit($key, microtime(true) - $startTime);
                $this->handleSlidingExpiration($key, $item, $ttl);

                return $value;
            }

            // Cache miss - record metrics
            $this->metricsCollector->recordMiss($key);

            // If no callback provided, return null
            if (null === $callback) {
                return null;
            }

            // Use stampede protection to generate value
            return $this->stampedeProtector->get($key, function () use ($callback, $key, $ttl, $tags, $startTime) {
                $value = $callback();
                $this->setValue($key, $value, $ttl, $tags);
                $this->metricsCollector->recordGeneration($key, microtime(true) - $startTime);

                return $value;
            });
        } catch (\Exception $e) {
            $this->logger->error('Cache get operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If callback provided, try to execute it as fallback
            if (null !== $callback) {
                try {
                    return $callback();
                } catch (\Exception $callbackException) {
                    throw CacheException::operationFailed('get', $key, $callbackException);
                }
            }

            throw CacheException::operationFailed('get', $key, $e);
        }
    }

    /**
     * Simple get method (PSR-16 style).
     */
    public function getSimple(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->get($key);

            return $value ?? $default;
        } catch (CacheException $e) {
            $this->logger->warning('Cache get failed, returning default', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Set a value in cache with optional tagging.
     */
    public function set(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        try {
            $this->validateKey($key);

            return $this->setValue($key, $value, $ttl, $tags);
        } catch (\Exception $e) {
            $this->logger->error('Cache set operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('set', $key, $e);
        }
    }

    /**
     * Atomic increment operation.
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $this->validateKey($key);

        try {
            return $this->layeredCache->increment($key, $step, $initialValue, $ttl);
        } catch (\Exception $e) {
            // Fallback to non-atomic increment for backends that don't support it
            $current = $this->getSimple($key, $initialValue);
            if (!is_int($current)) {
                $current = $initialValue;
            }
            $newValue = $current + $step;
            $this->set($key, $newValue, $ttl);

            return $newValue;
        }
    }

    /**
     * Atomic decrement operation.
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    /**
     * Add a value only if the key doesn't exist.
     */
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

    /**
     * Check if a key exists in cache.
     */
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

    /**
     * Delete a specific key.
     */
    public function delete(string $key): bool
    {
        try {
            $this->validateKey($key);

            return $this->layeredCache->deleteItem($key);
        } catch (\Exception $e) {
            $this->logger->error('Cache delete operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('delete', $key, $e);
        }
    }

    /**
     * Delete multiple keys.
     */
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

    /**
     * Invalidate all cache items with specific tags.
     */
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

    /**
     * Clear all cache items.
     */
    public function clear(): bool
    {
        try {
            $this->metricsCollector->recordClear();

            return $this->layeredCache->clear();
        } catch (\Exception $e) {
            $this->logger->error('Cache clear operation failed', [
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('clear', 'all', $e);
        }
    }

    /**
     * Get multiple values at once.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getSimple($key, $default);
        }

        return $result;
    }

    /**
     * Set multiple values at once.
     */
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

    /**
     * Get cache statistics and metrics.
     */
    public function getMetrics(): array
    {
        return $this->metricsCollector->getMetrics();
    }

    /**
     * Advanced API access for PSR-6 compatibility.
     */
    public function advanced(): CacheItemPool
    {
        return new AdvancedCacheProxy($this->layeredCache, $this->serializer);
    }

    /**
     * Warm cache with predefined data.
     */
    public function warm(array $data, $ttl = null): void
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        $this->logger->info('Cache warmed', ['keys' => array_keys($data)]);
    }

    /**
     * Subscribe cache item to invalidation topics.
     */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->layeredCache->subscribeTo($key, $topics);
    }

    /**
     * Trigger invalidation event for topics.
     */
    public function invalidateTopics(array $topics): bool
    {
        return $this->layeredCache->invalidateTopics($topics);
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

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > 250) {
            throw InvalidArgumentException::keyTooLong($key);
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }
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
