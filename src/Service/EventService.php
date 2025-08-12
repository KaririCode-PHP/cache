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
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerRegistry;
use KaririCode\Logging\SilentLogger;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Advanced service for dispatching and logging cache-related events.
 *
 * This class centralizes all event and logging logic, following SOLID principles.
 * Its sole responsibility is to orchestrate the dispatching of PSR-14 events and the
 * recording of structured logs, providing complete observability of the cache subsystem.
 *
 * Key Features:
 * - PSR-14 event dispatching for integration with other systems.
 * - Structured, context-rich logging for every cache operation.
 * - Performance monitoring with configurable thresholds to detect slow operations.
 * - Secure handling of sensitive data, ensuring it is not exposed in logs.
 * - Resilient architecture with fallback loggers to prevent failures in case of
 * a misconfigured logging system.
 * - Support for multiple log channels (cache, performance, errors) for more
 * granular analysis.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/docs/cache/events
 */
final class EventService
{
    // Constants for log channel names, ensuring consistency.
    private const LOG_CHANNEL_CACHE = 'cache';
    private const LOG_CHANNEL_PERFORMANCE = 'performance';
    private const LOG_CHANNEL_ERROR = 'error';
    private const LOG_CHANNEL_ASYNC = 'async';
    private const FALLBACK_CHANNEL = 'system';

    // Properties for specialized loggers, ensuring segregation of logging responsibilities.
    private readonly Logger $cacheLogger;
    private readonly Logger $performanceLogger;
    private readonly Logger $errorLogger;
    private readonly Logger $asyncLogger;
    private readonly Logger $fallbackLogger;

    /**
     * Constructor for the EventService.
     *
     * @param EventDispatcherInterface|null $dispatcher the PSR-14 event dispatcher (optional)
     * @param LoggerRegistry|null $loggerRegistry the central logger registry (optional)
     * @param EventServiceConfig $config the DTO with configurations for this service
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?LoggerRegistry $loggerRegistry = null,
        private readonly EventServiceConfig $config = new EventServiceConfig()
    ) {
        // Initializes all necessary loggers with a robust fallback mechanism.
        $this->initializeLoggers($loggerRegistry);
    }

    /**
     * Dispatches a cache hit event.
     *
     * @param string $key the key of the item found in the cache
     * @param mixed $value the retrieved value
     * @param float $duration the duration of the retrieval operation in seconds
     */
    public function dispatchCacheHit(string $key, mixed $value, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $event = new CacheHitEvent($key, $value, $duration);
        $context = $this->buildCacheEventContext($key, [
            'event_type' => 'cache_hit',
            'value_type' => get_debug_type($value),
            'value_size_bytes' => $this->calculateValueSize($value),
            'retrieval_duration_ms' => $this->formatDurationMs($duration),
        ]);

        $this->logCacheHit($key, $context);
        $this->evaluatePerformance($duration, 'cache_hit', $key, $context);
        $this->dispatchEvent($event);
    }

    /**
     * Dispatches a cache miss event.
     *
     * @param string $key the key of the item not found
     */
    public function dispatchCacheMiss(string $key): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $event = new CacheMissEvent($key);
        $context = $this->buildCacheEventContext($key, ['event_type' => 'cache_miss']);

        $this->logCacheMiss($key, $context);
        $this->dispatchEvent($event);
    }

    /**
     * Dispatches a cache set event.
     *
     * @param string $key the key of the item to be stored
     * @param mixed $value the value to be stored
     * @param int|null $ttl the time-to-live (TTL) in seconds
     * @param float $duration the duration of the storage operation in seconds
     */
    public function dispatchCacheSet(string $key, mixed $value, ?int $ttl = 0, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $event = new CacheSetEvent($key, $value, $ttl, $duration);
        $context = $this->buildCacheEventContext($key, [
            'event_type' => 'cache_set',
            'value_size_bytes' => $this->calculateValueSize($value),
            'ttl_seconds' => $ttl,
            'ttl_human' => $this->formatTtlHuman($ttl),
            'storage_duration_ms' => $this->formatDurationMs($duration),
        ]);

        $this->logCacheSet($key, $context);
        $this->evaluatePerformance($duration, 'cache_set', $key, $context);
        $this->dispatchEvent($event);
    }

    /**
     * Dispatches a cache delete event.
     *
     * @param string $key the key of the item to be removed
     * @param bool $success indicates whether the removal was successful
     * @param float $duration the duration of the operation in seconds
     */
    public function dispatchCacheDelete(string $key, bool $success, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $event = new CacheDeleteEvent($key, $success, $duration);
        $context = $this->buildCacheEventContext($key, [
            'event_type' => 'cache_delete',
            'operation_success' => $success,
            'deletion_duration_ms' => $this->formatDurationMs($duration),
        ]);

        $this->logCacheDelete($key, $success, $context);
        $this->evaluatePerformance($duration, 'cache_delete', $key, $context);
        $this->dispatchEvent($event);
    }

    /**
     * Dispatches a cache clear event.
     *
     * @param float $duration the duration of the operation in seconds
     */
    public function dispatchCacheClear(float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $event = new CacheClearEvent(null, $duration);
        $context = $this->buildBaseContext([
            'event_type' => 'cache_clear',
            'clear_duration_ms' => $this->formatDurationMs($duration),
            'impact' => 'all_cache_entries',
        ]);

        $this->logCacheClear($context);
        $this->evaluatePerformance($duration, 'cache_clear', '*', $context);
        $this->dispatchEvent($event);
    }

    /**
     * Dispatches a tag invalidation event.
     *
     * @param iterable<string> $tags the tags being invalidated
     * @param float $affectedKeys the number of affected keys
     * @param float $duration the duration of the operation in seconds
     */
    public function dispatchTagInvalidation(iterable $tags, float $affectedKeys, float $duration = 0.0): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $tagStrings = is_array($tags) ? $tags : iterator_to_array($tags);
        $event = new TagInvalidationEvent($tagStrings, $duration, $affectedKeys);
        $context = $this->buildBaseContext([
            'event_type' => 'tag_invalidation',
            'tags' => $tagStrings,
            'tags_count' => count($tagStrings),
            'affected_keys_count' => $affectedKeys,
            'invalidation_duration_ms' => $this->formatDurationMs($duration),
            'efficiency_ratio' => $this->calculateEfficiencyRatio(count($tagStrings), $affectedKeys),
        ]);

        $this->logTagInvalidation($tagStrings, $context);
        $this->evaluatePerformance($duration, 'tag_invalidation', implode(',', $tagStrings), $context);
        $this->dispatchEvent($event);
    }

    /**
     * Dispatches an atomic operation event.
     *
     * @param string $operation The name of the operation (e.g., 'increment').
     * @param string $key the key involved
     * @param mixed $result the result of the operation
     * @param float $duration the duration of the operation in seconds
     * @param array<string, mixed> $data additional operation-specific data
     */
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

        $event = new CacheAtomicOperationEvent($operation, $key, $result, $duration, $data);
        $context = $this->buildCacheEventContext($key, [
            'event_type' => 'atomic_operation',
            'operation_name' => $operation,
            'operation_result' => $result,
            'operation_data' => $this->sanitizeData($data),
            'execution_duration_ms' => $this->formatDurationMs($duration),
        ]);

        $this->logAtomicOperation($operation, $key, $context);
        $this->evaluatePerformance($duration, 'atomic_operation', $key, $context);
        $this->dispatchEvent($event);
    }

    // ========================================================================
    // Private Internal Logic Methods
    // ========================================================================

    /**
     * Initializes the specialized loggers, ensuring a valid logger is always available.
     *
     * @param LoggerRegistry|null $loggerRegistry the application's logger registry
     */
    private function initializeLoggers(?LoggerRegistry $loggerRegistry): void
    {
        $this->fallbackLogger = $this->createFallbackLogger($loggerRegistry);

        if (null === $loggerRegistry) {
            $this->setAllLoggersToFallback();

            return;
        }

        $this->cacheLogger = $this->getLoggerSafely($loggerRegistry, self::LOG_CHANNEL_CACHE);
        $this->performanceLogger = $this->getLoggerSafely($loggerRegistry, self::LOG_CHANNEL_PERFORMANCE);
        $this->errorLogger = $this->getLoggerSafely($loggerRegistry, self::LOG_CHANNEL_ERROR);
        $this->asyncLogger = $this->getLoggerSafely($loggerRegistry, self::LOG_CHANNEL_ASYNC);
    }

    /**
     * Creates a fallback logger to ensure the application does not fail if logging is misconfigured.
     *
     * @param LoggerRegistry|null $loggerRegistry the logger registry
     *
     * @return Logger a guaranteed logger instance
     */
    private function createFallbackLogger(?LoggerRegistry $loggerRegistry): Logger
    {
        // Use the null coalescing operator to return a SilentLogger if the registry's logger is null.
        // This resolves the original TypeError, as it ensures the $fallbackLogger property never receives null.
        return $loggerRegistry?->getLogger(self::FALLBACK_CHANNEL) ?? new SilentLogger();
    }

    /**
     * Sets all logger properties to the fallback instance.
     * Called when the LoggerRegistry is not provided.
     */
    private function setAllLoggersToFallback(): void
    {
        $this->cacheLogger = $this->fallbackLogger;
        $this->performanceLogger = $this->fallbackLogger;
        $this->errorLogger = $this->fallbackLogger;
        $this->asyncLogger = $this->fallbackLogger;
    }

    /**
     * Safely gets a logger, using the fallback if the channel does not exist.
     *
     * @param LoggerRegistry $registry the logger registry
     * @param string $channel the desired log channel
     *
     * @return Logger the logger instance
     */
    private function getLoggerSafely(LoggerRegistry $registry, string $channel): Logger
    {
        return $registry->getLogger($channel) ?? $this->fallbackLogger;
    }

    /**
     * Centralizes event dispatching, including error handling.
     *
     * @param object $event the event to be dispatched
     */
    private function dispatchEvent(object $event): void
    {
        if (null === $this->dispatcher) {
            return;
        }

        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->handleDispatcherError($event, $e);
        }
    }

    /**
     * Executes a logging operation within a try-catch block to prevent logger failures
     * from affecting the main application.
     *
     * @param callable $logOperation the logging operation to execute
     * @param string $errorMessage the error message in case of failure
     * @param array<string, mixed> $context the log context
     */
    private function executeLoggingWithFallback(callable $logOperation, string $errorMessage, array $context): void
    {
        try {
            $logOperation();
        } catch (\Throwable $e) {
            $this->logToErrorChannel($errorMessage, [
                'error' => $e->getMessage(),
                'context_keys' => array_keys($context),
            ]);
        }
    }

    /**
     * Evaluates the performance of an operation and logs a warning if it exceeds the threshold.
     *
     * @param float $duration duration of the operation in seconds
     * @param string $operation name of the operation
     * @param string $key the cache key
     * @param array<string, mixed> $context the log context
     */
    private function evaluatePerformance(float $duration, string $operation, string $key, array $context): void
    {
        if (!$this->config->performanceLoggingEnabled) {
            return;
        }

        $thresholdMs = $this->config->getPerformanceThreshold($operation);
        $durationMs = $this->formatDurationMs($duration);

        if ($durationMs > $thresholdMs) {
            $this->logSlowOperation($operation, $key, $durationMs, $thresholdMs, $context);
        }
    }

    /**
     * Builds the base context for all event logs.
     *
     * @param array<string, mixed> $additionalContext additional data
     *
     * @return array<string, mixed> the complete and sanitized context
     */
    private function buildBaseContext(array $additionalContext = []): array
    {
        $baseContext = [
            'component' => 'cache',
            'service' => 'event_service',
            'environment' => $_ENV['KARIRICODE_APP'] ?? 'unknown',
            'process_id' => getmypid(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'timestamp' => microtime(true),
        ];

        return $this->sanitizeContext(array_merge($baseContext, $additionalContext));
    }

    /**
     * Builds the context for cache-key-specific events.
     *
     * @param string $key the cache key
     * @param array<string, mixed> $additionalContext additional data
     *
     * @return array<string, mixed> the complete and sanitized context
     */
    private function buildCacheEventContext(string $key, array $additionalContext = []): array
    {
        $keyContext = [
            'cache_key' => $key,
            'key_length' => strlen($key),
            'key_hash' => hash('xxh3', $key),
        ];

        return $this->buildBaseContext(array_merge($keyContext, $additionalContext));
    }

    /**
     * Logs a cache hit event.
     *
     * @param string $key the cache key
     * @param array<string, mixed> $context the log context
     */
    private function logCacheHit(string $key, array $context): void
    {
        $this->executeLoggingWithFallback(
            fn () => $this->asyncLogger->debug('Cache hit: Key retrieved successfully', $context),
            'Cache hit logging failed',
            $context
        );
    }

    /**
     * Logs a cache miss event.
     *
     * @param string $key the cache key
     * @param array<string, mixed> $context the log context
     */
    private function logCacheMiss(string $key, array $context): void
    {
        $this->executeLoggingWithFallback(
            fn () => $this->asyncLogger->info('Cache miss: Key not found or expired', $context),
            'Cache miss logging failed',
            $context
        );
    }

    /**
     * Logs a cache set event.
     *
     * @param string $key the cache key
     * @param array<string, mixed> $context the log context
     */
    private function logCacheSet(string $key, array $context): void
    {
        $this->executeLoggingWithFallback(
            fn () => $this->asyncLogger->debug('Cache set: Key stored successfully', $context),
            'Cache set logging failed',
            $context
        );
    }

    /**
     * Logs a cache delete event.
     *
     * @param string $key the cache key
     * @param bool $success whether the operation was successful
     * @param array<string, mixed> $context the log context
     */
    private function logCacheDelete(string $key, bool $success, array $context): void
    {
        $message = $success ? 'Cache delete: Key removed successfully' : 'Cache delete: Key not found';
        $logOperation = $success ?
            fn () => $this->asyncLogger->debug($message, $context) :
            fn () => $this->cacheLogger->warning($message, $context);

        $this->executeLoggingWithFallback($logOperation, 'Cache delete logging failed', $context);
    }

    /**
     * Logs a cache clear event.
     *
     * @param array<string, mixed> $context the log context
     */
    private function logCacheClear(array $context): void
    {
        $this->executeLoggingWithFallback(
            fn () => $this->cacheLogger->info('Cache clear: All cache entries removed', $context),
            'Cache clear logging failed',
            $context
        );
    }

    /**
     * Logs a tag invalidation event.
     *
     * @param array<string> $tags the invalidated tags
     * @param array<string, mixed> $context the log context
     */
    private function logTagInvalidation(array $tags, array $context): void
    {
        $this->executeLoggingWithFallback(
            fn () => $this->cacheLogger->info('Tag invalidation: Cache entries invalidated by tags', $context),
            'Tag invalidation logging failed',
            $context
        );
    }

    /**
     * Logs an atomic operation event.
     *
     * @param string $operation the operation name
     * @param string $key the cache key
     * @param array<string, mixed> $context the log context
     */
    private function logAtomicOperation(string $operation, string $key, array $context): void
    {
        $this->executeLoggingWithFallback(
            fn () => $this->asyncLogger->debug("Atomic operation: {$operation} executed", $context),
            'Atomic operation logging failed',
            $context
        );
    }

    /**
     * Logs a slow operation to the performance channel.
     *
     * @param string $operation the operation name
     * @param string $key the cache key
     * @param float $durationMs the actual duration in milliseconds
     * @param float $thresholdMs the configured threshold in milliseconds
     * @param array<string, mixed> $context the log context
     */
    private function logSlowOperation(
        string $operation,
        string $key,
        float $durationMs,
        float $thresholdMs,
        array $context
    ): void {
        $performanceContext = array_merge($context, [
            'performance_alert' => true,
            'threshold_ms' => $thresholdMs,
            'actual_duration_ms' => $durationMs,
            'slowness_factor' => round($durationMs / $thresholdMs, 2),
            'severity' => $this->calculateSeverity($durationMs, $thresholdMs),
        ]);

        $this->executeLoggingWithFallback(
            fn () => $this->performanceLogger->warning("Slow cache operation detected: {$operation}", $performanceContext),
            'Performance logging failed',
            $performanceContext
        );
    }

    /**
     * Sanitizes context data by redacting sensitive keys and truncating large payloads.
     *
     * @param array<string, mixed> $context the context array to sanitize
     *
     * @return array<string, mixed> the sanitized context array
     */
    private function sanitizeContext(array $context): array
    {
        foreach ($this->config->sensitiveKeys as $sensitiveKey) {
            if (isset($context[$sensitiveKey])) {
                $context[$sensitiveKey] = '[REDACTED]';
            }
        }

        $serialized = serialize($context);
        if (strlen($serialized) > $this->config->maxContextSize) {
            $context['_truncated'] = true;
            $context['_original_size'] = strlen($serialized);
        }

        return $context;
    }

    /**
     * Sanitizes a data array for logging.
     *
     * @param array<string, mixed> $data the data array
     *
     * @return array<string, mixed> the sanitized array
     */
    private function sanitizeData(array $data): array
    {
        return $this->sanitizeContext($data);
    }

    /**
     * Safely calculates the size of a value in bytes.
     *
     * @param mixed $value the value to measure
     *
     * @return int the size in bytes
     */
    private function calculateValueSize(mixed $value): int
    {
        try {
            return match (true) {
                is_string($value) => strlen($value),
                is_array($value), is_object($value) => strlen(serialize($value)),
                default => strlen((string) $value),
            };
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Formats a duration from seconds to milliseconds.
     *
     * @param float $duration duration in seconds
     *
     * @return float duration in milliseconds
     */
    private function formatDurationMs(float $duration): float
    {
        return round($duration * 1000, 3);
    }

    /**
     * Formats a TTL in seconds into a human-readable string.
     *
     * @param int|null $ttl TTL in seconds
     *
     * @return string human-readable TTL
     */
    private function formatTtlHuman(?int $ttl): string
    {
        if (!$ttl) {
            return 'no_expiration';
        }

        return match (true) {
            $ttl < 60 => "{$ttl}s",
            $ttl < 3600 => round($ttl / 60, 1) . 'm',
            $ttl < 86400 => round($ttl / 3600, 1) . 'h',
            default => round($ttl / 86400, 1) . 'd',
        };
    }

    /**
     * Calculates the efficiency ratio for tag invalidation operations.
     *
     * @param int $tagCount the number of tags
     * @param float $affectedKeys the number of keys affected
     *
     * @return float the efficiency ratio
     */
    private function calculateEfficiencyRatio(int $tagCount, float $affectedKeys): float
    {
        return $affectedKeys > 0 ? round($tagCount / $affectedKeys, 2) : 0;
    }

    /**
     * Calculates a severity level based on how much a slow operation exceeded its threshold.
     *
     * @param float $actualMs the actual duration in milliseconds
     * @param float $thresholdMs the threshold in milliseconds
     *
     * @return string the severity level ('low', 'medium', 'high', 'critical')
     */
    private function calculateSeverity(float $actualMs, float $thresholdMs): string
    {
        $factor = $actualMs / $thresholdMs;

        return match (true) {
            $factor >= 5.0 => 'critical',
            $factor >= 3.0 => 'high',
            $factor >= 2.0 => 'medium',
            default => 'low',
        };
    }

    /**
     * Handles errors that occur during event dispatching.
     *
     * @param object $event the event that caused the error
     * @param \Throwable $e the exception that was thrown
     */
    private function handleDispatcherError(object $event, \Throwable $e): void
    {
        if (!$this->config->logOnDispatchError) {
            return;
        }

        $this->logToErrorChannel('Event dispatcher failed', [
            'event_class' => get_class($event),
            'error_message' => $e->getMessage(),
            'stack_trace' => $this->config->includeStackTrace ? $e->getTraceAsString() : '[DISABLED]',
            'event_data' => $this->extractEventDataSafely($event),
        ]);
    }

    /**
     * Logs a message to the dedicated error channel with a final fallback.
     *
     * @param string $message the error message
     * @param array<string, mixed> $context the log context
     */
    private function logToErrorChannel(string $message, array $context): void
    {
        try {
            $this->errorLogger->error($message, $context);
        } catch (\Throwable $e) {
            // Last resort: attempt to log to the ultimate fallback logger.
            try {
                $this->fallbackLogger->error('Logging subsystem failure', [
                    'original_message' => $message,
                    'logging_error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Silent failure - logging infrastructure is completely down.
            }
        }
    }

    /**
     * Safely extracts data from an event object for logging purposes.
     *
     * @param object $event the event object
     *
     * @return array<string, mixed> an array of event data
     */
    private function extractEventDataSafely(object $event): array
    {
        try {
            $reflection = new \ReflectionClass($event);
            $data = [];
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $value = $property->getValue($event);
                $data[$property->getName()] = is_object($value) ? get_class($value) : $value;
            }

            return $this->sanitizeData($data);
        } catch (\Throwable) {
            return ['error' => 'Failed to extract event data'];
        }
    }
}
