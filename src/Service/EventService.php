<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Contract\Event\EventDispatcher;
use KaririCode\Cache\Event\CacheAtomicOperationEvent;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Service\Event\CacheEventContextBuilder;
use KaririCode\Cache\Service\Event\CacheEventLogger;
use KaririCode\Cache\Service\Event\CachePerformanceMonitor;
use KaririCode\Logging\LoggerRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates cache event dispatching and logging.
 * Refactored from God Class to follow Single Responsibility Principle.
 */
final class EventService implements EventDispatcher
{
    private readonly CacheEventLogger $logger;
    private readonly CacheEventContextBuilder $contextBuilder;
    private readonly CachePerformanceMonitor $performanceMonitor;

    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?LoggerRegistry $loggerRegistry = null,
        private readonly EventServiceConfig $config = new EventServiceConfig()
    ) {
        $this->logger = new CacheEventLogger($loggerRegistry, $config);
        $this->contextBuilder = new CacheEventContextBuilder($config);
        $this->performanceMonitor = new CachePerformanceMonitor($config);
    }

    public function dispatchCacheHit(string $key, mixed $value, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $context = $this->contextBuilder->buildContext($key, 'cache_hit', [
            'value_type' => get_debug_type($value),
            'value_size_bytes' => $this->contextBuilder->calculateValueSize($value),
            'retrieval_duration_ms' => $this->contextBuilder->formatDurationMs($duration),
        ]);

        $this->logger->logCacheOperation('cache_hit', 'Cache hit: Key retrieved successfully', $context);
        $this->checkAndLogPerformance($duration, 'cache_hit', $key, $context);
        $this->dispatchEvent(new CacheHitEvent($key, $value, $duration));
    }

    public function dispatchCacheMiss(string $key): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $context = $this->contextBuilder->buildContext($key, 'cache_miss');
        $this->logger->logCacheOperation('cache_miss', 'Cache miss: Key not found or expired', $context);
        $this->dispatchEvent(new CacheMissEvent($key));
    }

    public function dispatchCacheSet(string $key, mixed $value, ?int $ttl = 0, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $context = $this->contextBuilder->buildContext($key, 'cache_set', [
            'value_size_bytes' => $this->contextBuilder->calculateValueSize($value),
            'ttl_seconds' => $ttl,
            'ttl_human' => $this->contextBuilder->formatTtlHuman($ttl),
            'storage_duration_ms' => $this->contextBuilder->formatDurationMs($duration),
        ]);

        $this->logger->logCacheOperation('cache_set', 'Cache set: Key stored successfully', $context);
        $this->checkAndLogPerformance($duration, 'cache_set', $key, $context);
        $this->dispatchEvent(new CacheSetEvent($key, $value, $ttl, $duration));
    }

    public function dispatchCacheDelete(string $key, bool $success, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $context = $this->contextBuilder->buildContext($key, 'cache_delete', [
            'operation_success' => $success,
            'deletion_duration_ms' => $this->contextBuilder->formatDurationMs($duration),
        ]);

        $message = $success ? 'Cache delete: Key removed successfully' : 'Cache delete: Key not found';
        $this->logger->logCacheOperation('cache_delete', $message, $context);
        $this->checkAndLogPerformance($duration, 'cache_delete', $key, $context);
        $this->dispatchEvent(new CacheDeleteEvent($key, $success, $duration));
    }

    public function dispatchCacheClear(float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $context = $this->contextBuilder->buildContext('*', 'cache_clear', [
            'clear_duration_ms' => $this->contextBuilder->formatDurationMs($duration),
            'impact' => 'all_cache_entries',
        ]);

        $this->logger->logCacheOperation('cache_clear', 'Cache clear: All cache entries removed', $context);
        $this->checkAndLogPerformance($duration, 'cache_clear', '*', $context);
        $this->dispatchEvent(new CacheClearEvent(null, $duration));
    }

    public function dispatchTagInvalidation(iterable $tags, float $affectedKeys, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $tagStrings = is_array($tags) ? $tags : iterator_to_array($tags);
        $tagKey = implode(',', $tagStrings);

        $context = $this->contextBuilder->buildContext($tagKey, 'tag_invalidation', [
            'tags' => $tagStrings,
            'tags_count' => count($tagStrings),
            'affected_keys_count' => $affectedKeys,
            'invalidation_duration_ms' => $this->contextBuilder->formatDurationMs($duration),
            'efficiency_ratio' => $this->contextBuilder->calculateEfficiencyRatio(count($tagStrings), $affectedKeys),
        ]);

        $this->logger->logCacheOperation('tag_invalidation', 'Tag invalidation: Cache entries invalidated by tags', $context);
        $this->checkAndLogPerformance($duration, 'tag_invalidation', $tagKey, $context);
        $this->dispatchEvent(new TagInvalidationEvent($tagStrings, $duration, $affectedKeys));
    }

    public function dispatchAtomicOperation(
        string $operation,
        string $key,
        mixed $result,
        float $duration = 0.0,
        array $data = []
    ): void {
        if (!$this->config->enabled) {
            return;
        }

        $sanitizedData = $this->contextBuilder->sanitizeContext($data);
        $context = $this->contextBuilder->buildContext($key, 'atomic_operation', [
            'operation_name' => $operation,
            'operation_result' => $result,
            'operation_data' => $sanitizedData,
            'execution_duration_ms' => $this->contextBuilder->formatDurationMs($duration),
        ]);

        $this->logger->logCacheOperation('atomic_operation', "Atomic operation: {$operation} executed", $context);
        $this->checkAndLogPerformance($duration, 'atomic_operation', $key, $context);
        $this->dispatchEvent(new CacheAtomicOperationEvent($operation, $key, $result, $duration, $data));
    }

    private function checkAndLogPerformance(float $duration, string $operation, string $key, array $context): void
    {
        $performanceData = $this->performanceMonitor->checkPerformance($duration, $operation, $key);

        if (null !== $performanceData) {
            $this->logger->logPerformanceWarning(
                $operation,
                $duration,
                $performanceData['threshold_ms'],
                $context
            );
        }
    }

    private function dispatchEvent(object $event): void
    {
        if (null === $this->dispatcher) {
            return;
        }

        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            if (!$this->config->logOnDispatchError) {
                return;
            }

            $this->logger->logError('Event dispatcher failed', [
                'event_class' => get_class($event),
                'error_message' => $e->getMessage(),
                'stack_trace' => $this->config->includeStackTrace ? $e->getTraceAsString() : '[DISABLED]',
                'event_data' => $this->extractEventDataSafely($event),
            ]);
        }
    }

    private function extractEventDataSafely(object $event): array
    {
        try {
            $reflection = new \ReflectionClass($event);
            $data = [];

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $value = $property->getValue($event);
                $data[$property->getName()] = is_object($value) ? get_class($value) : $value;
            }

            return $this->contextBuilder->sanitizeContext($data);
        } catch (\Throwable) {
            return ['error' => 'Failed to extract event data'];
        }
    }
}
