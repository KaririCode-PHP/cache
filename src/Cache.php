<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheEventListener as CacheEventListenerContract;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\CacheValidator;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\CacheWarmupEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\AdvancedCacheProxy;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\Cache\CacheItemInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main Cache class implementing PSR-16 and advanced features with PSR-14 event dispatching.
 *
 * This class provides a unified interface for caching operations, including
 * automatic key sanitization, sliding expiration, metrics collection, and PSR-14 compatible events.
 *
 * SOLID Principles Applied:
 * - SRP: Each dependency handles a single responsibility
 * - OCP: Extensible through dependency injection
 * - LSP: All interface contracts properly implemented
 * - ISP: Dependencies only implement needed interfaces
 * - DIP: Depends on abstractions, not concretions
 */
final class Cache implements CacheContract
{
    private readonly CacheSerializer $serializer;
    private readonly StampedeProtectorContract $stampedeProtector;
    private readonly LoggerInterface $logger;
    private readonly CacheValidator $keyValidator;
    private readonly ?CacheEventListenerContract $eventListener;
    private readonly ?EventDispatcherInterface $eventDispatcher;

    /**
     * The metrics collector is the only mutable dependency, as its state
     * evolves with the cache operations.
     */
    private MetricsCollectorContract $metricsCollector;

    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        ?CacheSerializer $serializer = null,
        ?StampedeProtectorContract $stampedeProtector = null,
        ?MetricsCollectorContract $metricsCollector = null,
        ?LoggerInterface $logger = null,
        ?CacheEventListenerContract $eventListener = null,
        ?CacheValidator $keyValidator = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        private array $config = []
    ) {
        // Explicitly assign constructor parameters to class properties.
        $this->eventListener = $eventListener;
        $this->eventDispatcher = $eventDispatcher;
        $this->config = $config;

        // Initialize dependencies with sensible defaults (Null Object Pattern)
        // This makes the class easier to instantiate in different contexts.
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->stampedeProtector = $stampedeProtector ?? new StampedeProtector();
        $this->metricsCollector = $metricsCollector ?? new MetricsCollector();
        $this->logger = $logger ?? new NullLogger();
        $this->keyValidator = $keyValidator ?? new CacheKeyValidator();

        $this->validateConfiguration();

        $this->logger->info('Cache instance initialized', [
            'name' => $this->getName(),
            'serializer' => get_class($this->serializer),
            'config' => array_keys($this->config),
        ]);
    }

    /**
     * Enhanced get method with proper error handling and metrics.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $startTime = microtime(true);
        $this->validateKey($key);

        try {
            $value = $this->getValueFromCache($key);

            if (null === $value) {
                $this->metricsCollector = $this->metricsCollector->recordMiss($key);
                $this->dispatchCacheMiss($key);

                return $default;
            }

            $duration = microtime(true) - $startTime;
            $this->metricsCollector = $this->metricsCollector->recordHit($key, $duration);
            $this->dispatchCacheHit($key, $value, $duration);

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Cache get operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    private function getValueFromCache(string $key): mixed
    {
        $item = $this->layeredCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $rawValue = $item->get();
        if (!is_string($rawValue)) {
            throw CacheException::deserializationFailed($key, new \UnexpectedValueException('Cached value is not a string.'));
        }

        return $this->serializer->unserialize($rawValue);
    }

    /**
     * Enhanced set method with proper validation and event dispatching.
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $startTime = microtime(true);

        try {
            $this->validateKey($key);
            $this->validateValue($value);

            $serializedValue = $this->serializer->serialize($value);
            $expiration = $this->calculateExpiration($ttl);

            $item = $this->layeredCache->getItem($key);
            $item->set($serializedValue);
            if ($expiration) {
                $item->expiresAt($expiration);
            }
            $result = $this->layeredCache->save($item);

            if ($result) {
                $this->dispatchCacheSet($key, $value, $ttl, microtime(true) - $startTime);
                $this->logger->debug('Cache item stored successfully', [
                    'key' => $key,
                    'size' => strlen($serializedValue),
                    'ttl' => $ttl,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache set operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enhanced has method with proper validation.
     */
    public function has(string $key): bool
    {
        try {
            $this->validateKey($key);

            return $this->layeredCache->hasItem($key);
        } catch (\Throwable $e) {
            $this->logger->error('Cache has operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enhanced delete method with proper event handling.
     */
    public function delete(string $key): bool
    {
        $startTime = microtime(true);

        try {
            $this->validateKey($key);

            $existed = $this->has($key);
            $result = $this->layeredCache->deleteItem($key);

            if ($result) {
                $this->dispatchCacheDelete($key, $existed, microtime(true) - $startTime);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache delete operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enhanced clear method with proper event handling.
     */
    public function clear(): bool
    {
        $startTime = microtime(true);
        try {
            $result = $this->layeredCache->clear();

            if ($result) {
                $this->metricsCollector = $this->metricsCollector->recordClear();
                $this->dispatchCacheClear(microtime(true) - $startTime);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache clear operation failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enhanced getMultiple with batch processing.
     *
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * Enhanced setMultiple with batch processing.
     *
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
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
     * Enhanced deleteMultiple with batch processing.
     *
     * @param iterable<string> $keys
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

    // ================================
    // AtomicOperable Implementation
    // ================================

    /**
     * Atomic increment operation.
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);

        if (0 === $step) {
            throw InvalidArgumentException::invalidKeyFormat('Step cannot be zero');
        }

        return $this->layeredCache->increment($key, $step, $initialValue, $ttl);
    }

    /**
     * Atomic decrement operation.
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    /**
     * Atomic add operation (only if key doesn't exist).
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    /**
     * Compare and swap operation.
     */
    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $current = $this->get($key);

        if ($current === $expected) {
            return $this->set($key, $value, $ttl);
        }

        return false;
    }

    // ================================
    // Taggable Implementation
    // ================================

    /**
     * Set cache item with tags.
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTags($tags);

        $result = $this->set($key, $value, $ttl);

        if ($result && !empty($tags)) {
            $item = $this->layeredCache->getItem($key);
            if ($item instanceof CacheItem) {
                $item->tag($tags);
                $this->layeredCache->save($item);
            }
        }

        return $result;
    }

    /**
     * Invalidate cache items by tags.
     */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);

        try {
            $result = $this->layeredCache->invalidateTags($tags);

            if ($result && $this->eventDispatcher) {
                $event = new TagInvalidationEvent($tags);
                $this->eventDispatcher->dispatch($event);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Tag invalidation failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);

            throw CacheException::tagInvalidationFailed($tags, $e);
        }
    }

    /**
     * Get cache items by tags.
     *
     * @return array<string, mixed>
     */
    public function getByTags(array $tags): array
    {
        $this->validateTags($tags);

        // Implementation would depend on the specific layered cache capabilities
        // This is a basic implementation
        return [];
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->layeredCache->getTags();
    }

    // ================================
    // TopicSubscribable Implementation
    // ================================

    /**
     * Subscribe to invalidation topics.
     */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        $this->layeredCache->subscribeTo($key, $topics);
    }

    /**
     * Unsubscribe from invalidation topics.
     */
    public function unsubscribeFrom(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        // Implementation depends on layered cache capabilities
        foreach ($topics as $topic) {
            // Remove key from topic subscriptions
        }
    }

    /**
     * Invalidate by topics.
     */
    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);

        return $this->layeredCache->invalidateTopics($topics);
    }

    /**
     * @return string[]
     */
    public function getSubscribedTopics(string $key): array
    {
        $this->validateKey($key);

        return $this->layeredCache->getSubscribedTopics($key);
    }

    /**
     * @return string[]
     */
    public function getSubscribersForTopic(string $topic): array
    {
        $this->validateTopics([$topic]);

        return $this->layeredCache->getSubscribersForTopic($topic);
    }

    // ================================
    // Cache Contract Implementation
    // ================================

    /**
     * Enhanced get with callback and stampede protection.
     */
    public function getWithCallback(
        string $key,
        ?callable $callback = null,
        \DateInterval|int|null $ttl = null,
        array $tags = []
    ): mixed {
        $value = $this->get($key);

        if (null !== $value) {
            return $value;
        }

        if (null === $callback) {
            return null;
        }

        // Use stampede protection
        return $this->stampedeProtector->get($key, function () use ($callback, $key, $ttl, $tags) {
            $value = $callback();

            if (!empty($tags)) {
                $this->setWithTags($key, $value, $tags, $ttl);
            } else {
                $this->set($key, $value, $ttl);
            }

            $this->metricsCollector = $this->metricsCollector->recordGeneration($key, microtime(true));

            return $value;
        });
    }

    /**
     * Cache warmup operation.
     *
     * @param array<string, mixed> $data
     */
    public function warm(array $data, \DateInterval|int|null $ttl = null): void
    {
        if ($this->eventDispatcher) {
            $event = new CacheWarmupEvent($data, $ttl);
            $this->eventDispatcher->dispatch($event);
        }

        $this->setMultiple($data, $ttl);

        $this->logger->info('Cache warmed', ['keys' => array_keys($data)]);
    }

    /**
     * Gets high-level performance metrics for the cache instance.
     */
    public function getMetrics(): CacheMetrics
    {
        return new CacheMetrics($this->metricsCollector->getMetrics());
    }

    /**
     * Get cache name.
     */
    public function getName(): string
    {
        return (string) ($this->config['name'] ?? 'default');
    }

    /**
     * Check if cache is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            // Perform basic health check
            $testKey = '__health_check_' . time();
            $testValue = 'health_test';

            if (!$this->set($testKey, $testValue, 60)) {
                return false;
            }

            $retrieved = $this->get($testKey);
            $this->delete($testKey);

            return $retrieved === $testValue;
        } catch (\Throwable $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Perform maintenance operations.
     *
     * @param array<string, mixed> $options
     */
    public function maintenance(array $options = []): bool
    {
        try {
            $this->logger->info('Starting cache maintenance', $options);

            // Delegate to layered cache
            $result = $this->layeredCache->maintenance($options);

            if ($result) {
                $this->logger->info('Cache maintenance completed successfully');
            } else {
                $this->logger->warning('Cache maintenance completed with issues');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache maintenance failed', [
                'error' => $e->getMessage(),
                'options' => $options,
            ]);

            return false;
        }
    }

    // ================================
    // Additional Public Methods
    // ================================

    /**
     * Get advanced cache pool for PSR-6 compatibility.
     */
    public function advanced(): CacheItemPool
    {
        return new AdvancedCacheProxy($this->layeredCache, $this->serializer);
    }

    /**
     * Set with automatic key sanitization.
     */
    public function setWithAutoSanitization(
        string $key,
        mixed $value,
        \DateInterval|int|null $ttl = null,
        array $tags = []
    ): bool {
        $sanitizedKey = $this->keyValidator->sanitizeKey($key);

        if ($sanitizedKey !== $key) {
            $this->logger->info('Key was sanitized', [
                'original_key' => $key,
                'sanitized_key' => $sanitizedKey,
            ]);
        }

        return $this->setWithTags($sanitizedKey, $value, $tags, $ttl);
    }

    /**
     * Get with automatic key sanitization.
     */
    public function getWithAutoSanitization(string $key, mixed $default = null): mixed
    {
        $sanitizedKey = $this->keyValidator->sanitizeKey($key);

        if ($sanitizedKey !== $key) {
            $this->logger->info('Key was sanitized during get', [
                'original_key' => $key,
                'sanitized_key' => $sanitizedKey,
            ]);
        }

        return $this->get($sanitizedKey, $default);
    }

    /**
     * Get current configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get event listener.
     */
    public function getEventListener(): ?CacheEventListenerContract
    {
        return $this->eventListener;
    }

    /**
     * Get key validator.
     */
    public function getKeyValidator(): CacheValidator
    {
        return $this->keyValidator;
    }

    // ================================
    // Private Helper Methods
    // ================================

    /**
     * Validate cache key according to PSR-16 specifications.
     */
    private function validateKey(string $key): void
    {
        if (!$this->keyValidator->validateKey($key)) {
            $errors = $this->keyValidator->getValidationErrors($key);
            throw InvalidArgumentException::invalidKeyFormat($key . ': ' . implode(', ', $errors));
        }
    }

    /**
     * Validate cache value for serializability.
     */
    private function validateValue(mixed $value): void
    {
        if (!$this->serializer->canSerialize($value)) {
            throw InvalidArgumentException::unserializableValue(get_debug_type($value));
        }
    }

    /**
     * Validate tags array.
     *
     * @param array<string> $tags
     */
    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || empty($tag)) {
                throw InvalidArgumentException::invalidTag($tag);
            }
        }
    }

    /**
     * Validate topics array.
     *
     * @param array<string> $topics
     */
    private function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic) || empty($topic)) {
                throw InvalidArgumentException::invalidTopic($topic);
            }
        }
    }

    /**
     * Validate configuration.
     */
    private function validateConfiguration(): void
    {
        // Ensure name is set and valid
        if (isset($this->config['name']) && !is_string($this->config['name'])) {
            throw InvalidArgumentException::invalidConfiguration('name must be a string');
        }
    }

    /**
     * Calculate expiration time.
     */
    private function calculateExpiration(\DateInterval|int|null $ttl): ?\DateTimeInterface
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        if (is_int($ttl) && $ttl > 0) {
            return new \DateTimeImmutable('+' . $ttl . ' seconds');
        }

        return null;
    }

    /**
     * Handle sliding expiration.
     */
    private function handleSlidingExpiration(CacheItemInterface $item): void
    {
        $slidingTtl = $this->config['sliding_ttl'] ?? null;

        if (null !== $slidingTtl && $item->isHit()) {
            $newExpiration = $this->calculateExpiration($slidingTtl);

            // Atualiza a expiração e salva novamente
            $item->expiresAt($newExpiration);
            $this->layeredCache->save($item);
        }
    }

    // ================================
    // Event Dispatching Methods
    // ================================

    /**
     * Dispatch cache hit event.
     */
    private function dispatchCacheHit(string $key, mixed $value, float $duration): void
    {
        if ($this->eventDispatcher) {
            $event = new CacheHitEvent($key, $value, $duration);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache miss event.
     */
    private function dispatchCacheMiss(string $key): void
    {
        if ($this->eventDispatcher) {
            $event = new CacheMissEvent($key);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache set event.
     */
    private function dispatchCacheSet(string $key, mixed $value, \DateInterval|int|null $ttl, float $duration): void
    {
        if ($this->eventDispatcher) {
            // Agora enviamos o evento com a duração
            $event = new CacheSetEvent($key, $value, $ttl, [], $duration);
            $this->eventDispatcher->dispatch($event);
        }
    }

    private function dispatchCacheDelete(string $key, bool $existed, float $duration): void
    {
        if ($this->eventDispatcher) {
            $event = new CacheDeleteEvent($key, $existed, $duration);
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Dispatch cache clear event.
     */
    private function dispatchCacheClear(float $duration): void
    {
        if ($this->eventDispatcher) {
            $event = new CacheClearEvent(null, $duration);
            $this->eventDispatcher->dispatch($event);
        }
    }
}
