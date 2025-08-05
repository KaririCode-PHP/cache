<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheWarmupEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Enhanced AdvancedCacheOperations with proper TTL/expiration handling.
 *
 * This class handles advanced cache operations including callbacks, stampede
 * protection, cache warming, and metrics collection while maintaining
 * consistent TTL/expiration behavior across all operations.
 *
 * Key improvements:
 * - Consistent TTL/expiration handling using ExpirationProvider
 * - Enhanced stampede protection with proper TTL propagation
 * - Cache warming operations with TTL support
 * - Comprehensive metrics collection and reporting
 * - Compare-and-swap operations with TTL handling
 * - Enhanced error handling and logging
 * - PSR-14 event dispatching support
 * - Type safety with strict typing
 *
 * Design Patterns Applied:
 * - Strategy Pattern: ExpirationProvider for TTL management
 * - Decorator Pattern: StampedeProtector for cache stampede protection
 * - Observer Pattern: Event dispatching and metrics collection
 * - Template Method: Consistent operation flow
 * - Factory Pattern: Component initialization
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       StampedeProtector for cache stampede protection
 * @see       ExpirationProvider for TTL management strategy
 */
final class AdvancedCacheOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TtlValidationTrait;

    private readonly StampedeProtectorContract $stampedeProtector;
    private MetricsCollectorContract $metricsCollector;

    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        private readonly ExpirationProvider $expirationProvider,
        private readonly CacheSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher,
        private readonly CacheConfig $config
    ) {
        $this->stampedeProtector = new StampedeProtector();
        $this->metricsCollector = new MetricsCollector();
    }

    // ========================================================================
    // Advanced Cache Operations with Enhanced TTL Support
    // ========================================================================
    public function getWithCallback(
        string $key,
        ?callable $callback = null,
        iterable $tags = [],
    ): mixed {
        $this->validateKey($key);

        $startTime = microtime(true);

        try {
            // Try to get from cache first
            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $value = $this->deserializeValue($item->get());
                $retrievalTime = microtime(true) - $startTime;

                $this->metricsCollector = $this->metricsCollector->recordHit($key, $retrievalTime);

                $this->logger->debug('Cache hit with callback', [
                    'key' => $key,
                    'retrieval_time_ms' => round($retrievalTime * 1000, 2),
                ]);

                return $value;
            }

            // Cache miss - use callback if provided
            if (null === $callback) {
                $this->metricsCollector = $this->metricsCollector->recordMiss($key);
                $this->dispatchMissEvent($key);

                $this->logger->debug('Cache miss without callback', ['key' => $key]);

                return null;
            }

            // Use stampede protection to prevent thundering herd
            return $this->stampedeProtector->get($key, function () use ($key, $callback, $tags, $startTime) {
                $generationStartTime = microtime(true);

                try {
                    $value = $callback();
                    $generationTime = microtime(true) - $generationStartTime;

                    // Store the generated value with proper TTL
                    $this->storeWithTagsAndTtl($key, $value, $tags);

                    $totalTime = microtime(true) - $startTime;

                    $this->metricsCollector = $this->metricsCollector
                        ->recordMiss($key)
                        ->recordGeneration($key, $generationTime);

                    $this->dispatchMissEvent($key);

                    $this->logger->info('Cache value generated', [
                        'key' => $key,
                        'generation_time_ms' => round($generationTime * 1000, 2),
                        'total_time_ms' => round($totalTime * 1000, 2),
                    ]);

                    return $value;
                } catch (\Throwable $exception) {
                    $this->logger->error('Cache callback execution failed', [
                        'key' => $key,
                        'error' => $exception->getMessage(),
                    ]);

                    throw CacheException::operationFailed('callback', $key, $exception);
                }
            }, $this->resolveTtlToSeconds($key, 'getWithCallback'));
        } catch (\Throwable $exception) {
            $this->logger->error('Get with callback operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('getWithCallback', $key, $exception);
        }
    }

    /**
     * Warms the cache with predefined data and proper TTL handling.
     *
     * @param iterable<string, mixed> $data Key-value pairs to cache
     * @param iterable<string|Tag> $tags Optional tags for all cached items
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When parameters are invalid
     *
     * @return array<string, bool> Results of warm-up operations per key
     *
     * @example
     * ```php
     * $warmupData = [
     *     'config:app' => $appConfig,
     *     'config:db' => $dbConfig,
     * ];
     * $results = $advancedOps->warm($warmupData, 3600, ['config']);
     * ```
     */
    public function warm(
        iterable $data,
        iterable $tags = []
    ): array {
        $data = $this->validateAndNormalizeValues($data);

        if (empty($data)) {
            return [];
        }

        $startTime = microtime(true);
        $results = [];

        try {
            $normalizedTags = $this->normalizeTags($tags);
            $expiration = $this->resolveExpiration('warmup_operation', 'warm');

            foreach ($data as $key => $value) {
                try {
                    $this->validateKey($key);
                    $this->validateValue($value);

                    if (!empty($normalizedTags)) {
                        $success = $this->layeredCache->setWithTags($key, $value, $normalizedTags, $expiration);
                    } else {
                        $item = new CacheItem($key, $this->serializeValue($value), false);
                        if (null !== $expiration) {
                            $item->expiresAt($expiration);
                        }
                        $success = $this->layeredCache->save($item);
                    }

                    $results[$key] = $success;
                } catch (\Throwable $exception) {
                    $results[$key] = false;
                    $this->logger->warning('Individual warm-up operation failed', [
                        'key' => $key,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $operationTime = microtime(true) - $startTime;
            $successCount = count(array_filter($results));

            $this->dispatchWarmupEvent(array_keys($data), $successCount, $operationTime);

            $this->logger->info('Cache warm-up completed', [
                'items_count' => count($data),
                'success_count' => $successCount,
                'failure_count' => count($data) - $successCount,
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $results;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache warm-up operation failed', [
                'items_count' => count($data),
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('warm', 'batch', $exception);
        }
    }

    /**
     * Compare-and-swap operation with proper TTL handling.
     *
     * @param string $key The cache key
     * @param mixed $expected The expected current value
     * @param mixed $value The new value to set
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When parameters are invalid
     *
     * @return bool True if the operation succeeded (current value matched expected)
     *
     * @example
     * ```php
     * // Atomic counter increment with TTL
     * $success = $advancedOps->compareAndSwap('counter', 10, 11, 3600);
     *
     * // Update with DateInterval TTL
     * $success = $advancedOps->compareAndSwap(
     *     'user:status',
     *     'offline',
     *     'online',
     *     new DateInterval('PT30M')
     * );
     * ```
     */
    public function compareAndSwap(
        string $key,
        mixed $expected,
        mixed $value
    ): bool {
        $this->validateKey($key);
        $this->validateValue($expected);
        $this->validateValue($value);

        $startTime = microtime(true);

        try {
            $expiration = $this->resolveExpiration($key, 'compareAndSwap');
            $success = $this->layeredCache->compareAndSwap($key, $expected, $value, $expiration);

            $operationTime = microtime(true) - $startTime;

            $this->logger->debug('Compare-and-swap completed', [
                'key' => $key,
                'success' => $success,
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Compare-and-swap operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('compareAndSwap', $key, $exception);
        }
    }

    /**
     * Gets high-level performance metrics for the cache instance.
     *
     * @return CacheMetrics Cache performance metrics as an immutable object
     */
    public function getMetrics(): CacheMetrics
    {
        $rawMetrics = $this->metricsCollector->getMetrics();

        return new CacheMetrics(
            $rawMetrics
        );
    }

    /**
     * Resets performance metrics.
     */
    public function resetMetrics(): void
    {
        $this->metricsCollector = $this->metricsCollector->reset();
        $this->logger->debug('Cache metrics reset');
    }

    /**
     * Performs cache maintenance operations.
     *
     * @param array<string, mixed> $options Maintenance options
     *
     * @throws CacheException When maintenance operation fails
     *
     * @return bool True if maintenance was successful
     */
    public function maintenance(array $options = []): bool
    {
        $startTime = microtime(true);

        try {
            $success = $this->layeredCache->maintenance($options);
            $operationTime = microtime(true) - $startTime;

            if ($options['reset_metrics'] ?? false) {
                $this->resetMetrics();
            }

            $this->logger->info('Cache maintenance completed', [
                'success' => $success,
                'options' => $options,
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Cache maintenance failed', [
                'options' => $options,
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('maintenance', 'system', $exception);
        }
    }

    /**
     * Checks if the cache is healthy and operational.
     *
     * @return bool True if cache is healthy
     */
    public function isHealthy(): bool
    {
        try {
            $testKey = '__health_check_' . uniqid();
            $testValue = microtime(true);

            // Test basic operations
            $setSuccess = $this->layeredCache->save(new CacheItem($testKey, $testValue, false));
            if (!$setSuccess) {
                return false;
            }

            $item = $this->layeredCache->getItem($testKey);
            if (!$item->isHit() || $item->get() !== $testValue) {
                return false;
            }

            $deleteSuccess = $this->layeredCache->deleteItem($testKey);
            if (!$deleteSuccess) {
                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Health check failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Stores a value with tags and TTL support.
     */
    private function storeWithTagsAndTtl(
        string $key,
        mixed $value,
        iterable $tags
    ): bool {
        $normalizedTags = $this->normalizeTags($tags);
        $expiration = $this->resolveExpiration($key, 'store');

        if (!empty($normalizedTags)) {
            return $this->layeredCache->setWithTags($key, $value, $normalizedTags, $expiration);
        }

        $serializedValue = $this->serializeValue($value);
        $item = new CacheItem($key, $serializedValue, false);

        if (null !== $expiration) {
            $item->expiresAt($expiration);
        }

        return $this->layeredCache->save($item);
    }

    /**
     * Resolves expiration time using ExpirationProvider strategy.
     */
    private function resolveExpiration(string $key, string $operation): ?\DateTimeInterface
    {
        $providerTtl = $this->expirationProvider->getTtlForKey($key);

        return $this->expirationProvider->calculateExpiration($providerTtl);
    }

    /**
     * Converts TTL to seconds for stampede protector.
     */
    private function resolveTtlToSeconds(string $key, string $operation): ?int
    {
        $expiration = $this->resolveExpiration($key, $operation);

        if (null === $expiration) {
            return null;
        }

        return max(0, $expiration->getTimestamp() - time());
    }

    /**
     * Serializes a value for storage.
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
     * Validates and normalizes an iterable of key-value pairs.
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
     * Normalizes mixed tag input to consistent Tag objects.
     */
    private function normalizeTags(iterable $tags): array
    {
        $normalizedTags = [];

        foreach ($tags as $tag) {
            if ($tag instanceof Tag) {
                $normalizedTags[] = $tag;
            } elseif (is_string($tag)) {
                if (empty(trim($tag))) {
                    throw InvalidArgumentException::invalidTag('Tag name cannot be empty');
                }
                $normalizedTags[] = new Tag($tag);
            } else {
                throw InvalidArgumentException::invalidTag('Tags must be strings or Tag objects, got: ' . get_debug_type($tag));
            }
        }

        return array_unique($normalizedTags, SORT_REGULAR);
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
     * Dispatches cache warmup event.
     */
    private function dispatchWarmupEvent(array $keys, int $successCount, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new CacheWarmupEvent($keys, $successCount, $duration);
        $this->eventDispatcher->dispatch($event);
    }
}
