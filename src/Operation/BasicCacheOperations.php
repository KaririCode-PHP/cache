<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlValidationTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced BasicCacheOperations with proper TTL/expiration handling.
 *
 * This class follows Clean Architecture principles and SOLID design patterns:
 * - Single Responsibility: Handles only basic CRUD cache operations
 * - Open/Closed: Extensible through composition and dependency injection
 * - Liskov Substitution: Can be substituted with other operation implementations
 * - Interface Segregation: Uses focused interfaces and traits
 * - Dependency Inversion: Depends on abstractions, not concretions
 *
 * Key improvements:
 * - Consistent TTL/expiration handling using ExpirationProvider
 * - Proper use of DateTimeImmutable for immutability
 * - Support for DateInterval, int, and null TTL values
 * - Enhanced error handling and validation
 * - Comprehensive metrics collection
 * - PSR-14 event dispatching support
 * - Type safety with strict typing
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       Clean Architecture by Robert C. Martin
 * @see       PSR-6: Caching Interface
 * @see       PSR-14: Event Dispatcher
 */
final class BasicCacheOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TtlValidationTrait;

    private readonly CacheSerializer $serializer;
    private readonly LoggerInterface $logger;
    private MetricsCollector $metricsCollector;

    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param LayeredCache $layeredCache The underlying cache implementation
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     * @param CacheSerializer|null $serializer Optional serializer (defaults to NativeSerializer)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 event dispatcher
     */
    public function __construct(
        private readonly LayeredCache $layeredCache,
        private readonly ExpirationProvider $expirationProvider,
        ?CacheSerializer $serializer = null,
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->logger = $logger ?? new NullLogger();
        $this->metricsCollector = new MetricsCollector();
    }

    // ========================================================================
    // Basic Cache Operations with Enhanced TTL Support
    // ========================================================================

    /**
     * Retrieves an item from the cache with comprehensive error handling.
     *
     * @param string $key The unique cache key
     * @param mixed $default Default value to return on cache miss
     *
     * @throws CacheException When cache operation fails
     *
     * @return mixed The cached value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $startTime = microtime(true);

        try {
            $item = $this->layeredCache->getItem($key);

            if (!$item->isHit()) {
                $this->metricsCollector = $this->metricsCollector->recordMiss($key);
                $this->dispatchMissEvent($key);

                $this->logger->debug('Cache miss', ['key' => $key]);

                return $default;
            }

            $value = $this->deserializeValue($item->get());
            $retrievalTime = microtime(true) - $startTime;

            $this->metricsCollector = $this->metricsCollector->recordHit($key, $retrievalTime);
            $this->dispatchHitEvent($key, $value, $retrievalTime);

            $this->logger->debug('Cache hit', [
                'key' => $key,
                'retrieval_time_ms' => round($retrievalTime * 1000, 2),
            ]);

            return $value;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache get operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw CacheException::operationFailed('get', $key, $exception);
        }
    }

    /**
     * Stores an item in the cache with proper TTL handling.
     *
     * @param string $key The unique cache key
     * @param mixed $value The value to store
     * @param \DateInterval|int|null $ttl Time-to-live (supports DateInterval, seconds as int, or null for default)
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When parameters are invalid
     *
     * @return bool True on success, false on failure
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTtl($ttl);

        $startTime = microtime(true);

        try {
            $expiration = $this->resolveExpiration($key, $ttl, 'set');
            $serializedValue = $this->serializeValue($value);

            $item = new CacheItem($key, $serializedValue, false);

            if (null !== $expiration) {
                $item->expiresAt($expiration);
            }

            $success = $this->layeredCache->save($item);
            $operationTime = microtime(true) - $startTime;

            if ($success) {
                $this->dispatchSetEvent($key, $value, $ttl, $operationTime);

                $this->logger->debug('Cache set successful', [
                    'key' => $key,
                    'ttl' => $this->formatTtlForLogging($ttl),
                    'expiration' => $expiration?->format('c'),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            } else {
                $this->logger->warning('Cache set failed', [
                    'key' => $key,
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            }

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache set operation failed', [
                'key' => $key,
                'ttl' => $this->formatTtlForLogging($ttl),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw CacheException::operationFailed('set', $key, $exception);
        }
    }

    /**
     * Checks if an item exists in the cache.
     *
     * @param string $key The cache key to check
     *
     * @throws CacheException When cache operation fails
     *
     * @return bool True if item exists and is not expired
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        try {
            $exists = $this->layeredCache->hasItem($key);

            $this->logger->debug('Cache has check', [
                'key' => $key,
                'exists' => $exists,
            ]);

            return $exists;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache has operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('has', $key, $exception);
        }
    }

    /**
     * Deletes an item from the cache.
     *
     * @param string $key The cache key to delete
     *
     * @throws CacheException When cache operation fails
     *
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $startTime = microtime(true);

        try {
            $existed = $this->layeredCache->hasItem($key);
            $success = $this->layeredCache->deleteItem($key);
            $operationTime = microtime(true) - $startTime;

            if ($success) {
                $this->dispatchDeleteEvent($key, $existed, $operationTime);

                $this->logger->debug('Cache delete successful', [
                    'key' => $key,
                    'existed' => $existed,
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            } else {
                $this->logger->warning('Cache delete failed', [
                    'key' => $key,
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            }

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache delete operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('delete', $key, $exception);
        }
    }

    /**
     * Clears all items from the cache.
     *
     * @throws CacheException When cache operation fails
     *
     * @return bool True on success, false on failure
     */
    public function clear(): bool
    {
        $startTime = microtime(true);

        try {
            $success = $this->layeredCache->clear();
            $operationTime = microtime(true) - $startTime;

            if ($success) {
                $this->metricsCollector = $this->metricsCollector->recordClear();

                $this->logger->info('Cache cleared successfully', [
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            } else {
                $this->logger->warning('Cache clear failed', [
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            }

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache clear operation failed', [
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('clear', 'all', $exception);
        }
    }

    // ========================================================================
    // Multi-Operation Methods with Enhanced TTL Support
    // ========================================================================

    /**
     * Retrieves multiple items from the cache.
     *
     * @param iterable<string> $keys Array of cache keys
     *
     * @throws CacheException When cache operation fails
     *
     * @return iterable<string, mixed> Array of key-value pairs
     */
    public function getMultiple(iterable $keys): iterable
    {
        $keys = $this->validateAndNormalizeKeys($keys);

        if (empty($keys)) {
            return [];
        }

        $startTime = microtime(true);
        $results = [];

        try {
            $items = $this->layeredCache->getItems($keys);

            foreach ($items as $key => $item) {
                if ($item->isHit()) {
                    $results[$key] = $this->deserializeValue($item->get());
                    $this->metricsCollector = $this->metricsCollector->recordHit($key, 0.0);
                } else {
                    $results[$key] = null;
                    $this->metricsCollector = $this->metricsCollector->recordMiss($key);
                }
            }

            $operationTime = microtime(true) - $startTime;

            $this->logger->debug('Cache getMultiple completed', [
                'keys_count' => count($keys),
                'hits' => count(array_filter($results, fn ($v) => null !== $v)),
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $results;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache getMultiple operation failed', [
                'keys_count' => count($keys),
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('getMultiple', implode(',', $keys), $exception);
        }
    }

    /**
     * Stores multiple items in the cache with proper TTL handling.
     *
     * @param iterable<string, mixed> $values Array of key-value pairs
     * @param \DateInterval|int|null $ttl Time-to-live for all items
     *
     * @throws CacheException When cache operation fails
     *
     * @return bool True if all items were stored successfully
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $values = $this->validateAndNormalizeValues($values);
        $this->validateTtl($ttl);

        if (empty($values)) {
            return true;
        }

        $startTime = microtime(true);
        $allSuccess = true;

        try {
            $expiration = $this->resolveExpiration('batch_operation', $ttl, 'setMultiple');
            $items = [];

            // Prepare all items
            foreach ($values as $key => $value) {
                $serializedValue = $this->serializeValue($value);
                $item = new CacheItem($key, $serializedValue, false);

                if (null !== $expiration) {
                    $item->expiresAt($expiration);
                }

                $items[] = $item;
            }

            // Store all items
            foreach ($items as $item) {
                if (!$this->layeredCache->save($item)) {
                    $allSuccess = false;
                }
            }

            $operationTime = microtime(true) - $startTime;

            $this->logger->debug('Cache setMultiple completed', [
                'items_count' => count($values),
                'success' => $allSuccess,
                'ttl' => $this->formatTtlForLogging($ttl),
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $allSuccess;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache setMultiple operation failed', [
                'items_count' => count($values),
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('setMultiple', 'batch', $exception);
        }
    }

    /**
     * Deletes multiple items from the cache.
     *
     * @param iterable<string> $keys Array of cache keys to delete
     *
     * @throws CacheException When cache operation fails
     *
     * @return bool True if all items were deleted successfully
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->validateAndNormalizeKeys($keys);

        if (empty($keys)) {
            return true;
        }

        $startTime = microtime(true);

        try {
            $success = $this->layeredCache->deleteItems($keys);
            $operationTime = microtime(true) - $startTime;

            $this->logger->debug('Cache deleteMultiple completed', [
                'keys_count' => count($keys),
                'success' => $success,
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache deleteMultiple operation failed', [
                'keys_count' => count($keys),
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('deleteMultiple', implode(',', $keys), $exception);
        }
    }

    // ========================================================================
    // Atomic Operations with Enhanced TTL Support
    // ========================================================================

    /**
     * Atomically increments a cache value with proper TTL handling.
     *
     * @param string $key The cache key
     * @param int $step The increment step (default: 1)
     * @param int $initialValue Initial value if key doesn't exist
     * @param \DateInterval|int|null $ttl Time-to-live for the item
     *
     * @throws CacheException When cache operation fails
     *
     * @return int The new value after increment
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateTtl($ttl);

        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Increment step cannot be zero');
        }

        try {
            $expiration = $this->resolveExpiration($key, $ttl, 'increment');
            $result = $this->layeredCache->increment($key, $step, $initialValue, $expiration);

            $this->logger->debug('Cache increment completed', [
                'key' => $key,
                'step' => $step,
                'initial_value' => $initialValue,
                'result' => $result,
                'ttl' => $this->formatTtlForLogging($ttl),
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache increment operation failed', [
                'key' => $key,
                'step' => $step,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('increment', $key, $exception);
        }
    }

    /**
     * Atomically decrements a cache value with proper TTL handling.
     *
     * @param string $key The cache key
     * @param int $step The decrement step (default: 1)
     * @param int $initialValue Initial value if key doesn't exist
     * @param \DateInterval|int|null $ttl Time-to-live for the item
     *
     * @throws CacheException When cache operation fails
     *
     * @return int The new value after decrement
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    /**
     * Adds an item to the cache if it doesn't exist with proper TTL handling.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param \DateInterval|int|null $ttl Time-to-live for the item
     *
     * @throws CacheException When cache operation fails
     *
     * @return bool True if item was added, false if it already exists
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTtl($ttl);

        try {
            if ($this->has($key)) {
                $this->logger->debug('Cache add failed - key exists', ['key' => $key]);

                return false;
            }

            $success = $this->set($key, $value, $ttl);

            $this->logger->debug('Cache add completed', [
                'key' => $key,
                'success' => $success,
                'ttl' => $this->formatTtlForLogging($ttl),
            ]);

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache add operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('add', $key, $exception);
        }
    }

    // ========================================================================
    // Metrics and Performance
    // ========================================================================

    /**
     * Gets current performance metrics.
     *
     * @return array<string, mixed> Performance metrics
     */
    public function getMetrics(): array
    {
        return $this->metricsCollector->getMetrics();
    }

    /**
     * Resets performance metrics.
     */
    public function resetMetrics(): void
    {
        $this->metricsCollector = $this->metricsCollector->reset();
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Resolves expiration time using ExpirationProvider strategy.
     *
     * @param string $key The cache key for context
     * @param \DateInterval|int|null $ttl The provided TTL
     * @param string $operation The operation type for context
     *
     * @return \DateTimeInterface|null The resolved expiration time
     */
    private function resolveExpiration(string $key, \DateInterval|int|null $ttl, string $operation): ?\DateTimeInterface
    {
        // Handle explicit null (no expiration)
        if (null === $ttl) {
            // Use ExpirationProvider to get appropriate TTL
            $providerTtl = $this->expirationProvider->getTtlForOperation($operation);

            return $this->expirationProvider->calculateExpiration($providerTtl);
        }

        // Handle DateInterval
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        // Handle integer (seconds)
        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return null; // No expiration for non-positive values
            }

            return new \DateTimeImmutable("+{$ttl} seconds");
        }

        // Fallback to ExpirationProvider
        $providerTtl = $this->expirationProvider->getTtlForKey($key);

        return $this->expirationProvider->calculateExpiration($providerTtl);
    }

    /**
     * Serializes a value for storage.
     *
     * @param mixed $value The value to serialize
     *
     * @throws CacheException When serialization fails
     *
     * @return mixed The serialized value
     */
    private function serializeValue(mixed $value): mixed
    {
        try {
            return $this->serializer->serialize($value);
        } catch (\Throwable $exception) {
            throw CacheException::serializationFailed('value', $exception);
        }
    }

    /**
     * Deserializes a value from storage.
     *
     * @param mixed $value The value to deserialize
     *
     * @throws CacheException When deserialization fails
     *
     * @return mixed The deserialized value
     */
    private function deserializeValue(mixed $value): mixed
    {
        try {
            return $this->serializer->unserialize($value);
        } catch (\Throwable $exception) {
            throw CacheException::deserializationFailed('value', $exception);
        }
    }

    /**
     * Validates and normalizes an iterable of keys.
     *
     * @param iterable<string> $keys
     *
     * @throws InvalidArgumentException When keys are invalid
     *
     * @return array<string>
     */
    private function validateAndNormalizeKeys(iterable $keys): array
    {
        $normalizedKeys = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('All keys must be strings');
            }

            $this->validateKey($key);
            $normalizedKeys[] = $key;
        }

        return array_unique($normalizedKeys);
    }

    /**
     * Validates and normalizes an iterable of key-value pairs.
     *
     * @param iterable<string, mixed> $values
     *
     * @throws InvalidArgumentException When values are invalid
     *
     * @return array<string, mixed>
     */
    private function validateAndNormalizeValues(iterable $values): array
    {
        $normalizedValues = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('All keys must be strings');
            }

            $this->validateKey($key);
            $this->validateValue($value);
            $normalizedValues[$key] = $value;
        }

        return $normalizedValues;
    }

    /**
     * Formats TTL for logging purposes.
     */
    private function formatTtlForLogging(\DateInterval|int|null $ttl): ?string
    {
        if (null === $ttl) {
            return 'default';
        }

        if ($ttl instanceof \DateInterval) {
            return $ttl->format('P%yY%mM%dDT%hH%iM%sS');
        }

        return "{$ttl}s";
    }

    // ========================================================================
    // Event Dispatching Methods
    // ========================================================================

    /**
     * Dispatches cache hit event.
     */
    private function dispatchHitEvent(string $key, mixed $value, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new CacheHitEvent($key, $value, $duration);
        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatches cache miss event.
     */
    private function dispatchMissEvent(string $key): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new CacheMissEvent($key);
        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatches cache set event.
     */
    private function dispatchSetEvent(string $key, mixed $value, \DateInterval|int|null $ttl, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new CacheSetEvent($key, $value, $ttl, $duration);
        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatches cache delete event.
     */
    private function dispatchDeleteEvent(string $key, bool $existed, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new CacheDeleteEvent($key, $existed, $duration);
        $this->eventDispatcher->dispatch($event);
    }
}
