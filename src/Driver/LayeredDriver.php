<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced Layered Cache Driver implementing Clean Architecture principles.
 *
 * This driver provides multi-tier caching with automatic fallback and promotion strategies.
 * Each layer represents a different cache backend with varying performance characteristics.
 *
 * Architecture:
 * - Layer 1 (Fastest): Memory-based caches (APCu, Array)
 * - Layer 2 (Fast): Network caches (Redis, Memcached)
 * - Layer 3 (Persistent): File-based caches (Filesystem)
 *
 * Design Patterns Applied:
 * - Chain of Responsibility: For layer traversal and fallback
 * - Circuit Breaker: Temporary layer disabling on failures
 * - Strategy: Different caching strategies per layer
 * - Decorator: Enhanced functionality over base drivers
 * - Template Method: Consistent operation flow across layers
 *
 * SOLID Principles:
 * - SRP: Each method has a single responsibility
 * - OCP: Open for extension through layer composition
 * - LSP: Substitutable with any CacheDriver implementation
 * - ISP: Implements only necessary interfaces
 * - DIP: Depends on abstractions, not concretions
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       https://docs.kariricode.org/cache/drivers/layered
 * @see       Clean Architecture by Robert C. Martin
 * @see       Design Patterns: Elements of Reusable Object-Oriented Software
 */
final class LayeredDriver implements
    CacheDriver,
    TaggableCacheDriver,
    AtomicOperable,
    CompareAndSwapAware,
    Maintainable
{
    /**
     * Cache layers ordered by priority (fastest first).
     *
     * @var array<int, CacheDriver>
     */
    private readonly array $layers;

    /**
     * Circuit breaker failure tracking per layer.
     *
     * @var array<int, CircuitBreakerState>
     */
    private array $circuitBreakers = [];

    /**
     * Performance metrics tracking.
     *
     * @var array<string, int>
     */
    private array $performanceMetrics = [
        'hits' => 0,
        'misses' => 0,
        'promotions' => 0,
        'failures' => 0,
        'circuit_breaker_trips' => 0,
    ];

    private const MAX_FAILURES_BEFORE_CIRCUIT_BREAK = 5;
    private const CIRCUIT_BREAKER_TIMEOUT_SECONDS = 60;
    private const LAYER_OPERATION_TIMEOUT_MS = 100;

    /**
     * Constructor applying Dependency Injection and Fail-Fast principles.
     *
     * @param array<CacheDriver> $layers Cache layers in priority order (fastest first)
     * @param ExpirationProvider $expirationProvider Shared expiration management
     * @param LoggerInterface|null $logger Optional logger for observability
     *
     * @throws ConfigurationException if layers configuration is invalid
     */
    public function __construct(
        array $layers,
        private readonly ExpirationProvider $expirationProvider,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->validateLayersConfiguration($layers);
        $this->layers = array_values($layers); // Ensure sequential indexing
        $this->initializeCircuitBreakers();

        $this->logger->info('LayeredDriver initialized', [
            'layer_count' => count($this->layers),
            'layer_types' => $this->getLayerTypes(),
        ]);
    }

    // ========================================================================
    // Core Cache Operations (CacheDriver Interface)
    // ========================================================================

    /**
     * Retrieves value using Chain of Responsibility pattern with promotion.
     *
     * Performance Optimization: Checks fastest layers first, promotes on cache hit.
     */
    public function get(string $key): mixed
    {
        $this->validateKey($key);

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                $value = $this->executeWithTimeout(
                    fn () => $layer->get($key),
                    self::LAYER_OPERATION_TIMEOUT_MS
                );

                if (null !== $value) {
                    $this->recordLayerSuccess($layerIndex);
                    ++$this->performanceMetrics['hits'];

                    // Promote to faster layers asynchronously for performance
                    $this->promoteToFasterLayers($key, $value, $layerIndex);

                    $this->logger->debug('Cache hit', [
                        'key' => $key,
                        'layer' => $layerIndex,
                        'layer_type' => $this->getLayerType($layerIndex),
                    ]);

                    return $value;
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'get', $key);
            }
        }

        ++$this->performanceMetrics['misses'];
        $this->logger->debug('Cache miss', ['key' => $key]);

        return null;
    }

    /**
     * Stores value using write-through strategy for consistency.
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $resolvedExpiration = $this->resolveExpiration($key, $expiration);
        $success = false;
        $successfulLayers = [];

        // Write-through strategy: attempt to write to all available layers
        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                $layerSuccess = $this->executeWithTimeout(
                    fn () => $layer->set($key, $value, $resolvedExpiration),
                    self::LAYER_OPERATION_TIMEOUT_MS
                );

                if ($layerSuccess) {
                    $success = true;
                    $successfulLayers[] = $layerIndex;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'set', $key);
            }
        }

        $this->logger->debug('Cache set operation', [
            'key' => $key,
            'success' => $success,
            'successful_layers' => $successfulLayers,
            'total_layers' => count($this->layers),
        ]);

        return $success;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($this->executeWithTimeout(
                    fn () => $layer->has($key),
                    self::LAYER_OPERATION_TIMEOUT_MS
                )) {
                    $this->recordLayerSuccess($layerIndex);

                    return true;
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'has', $key);
            }
        }

        return false;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $success = false;

        // Delete from all layers to maintain consistency
        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($this->executeWithTimeout(
                    fn () => $layer->delete($key),
                    self::LAYER_OPERATION_TIMEOUT_MS
                )) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'delete', $key);
            }
        }

        return $success;
    }

    public function clear(): bool
    {
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($this->executeWithTimeout(
                    fn () => $layer->clear(),
                    self::LAYER_OPERATION_TIMEOUT_MS * 10 // Clear operations may take longer
                )) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'clear', 'all');
            }
        }

        // Reset performance metrics after clear
        $this->resetPerformanceMetrics();

        return $success;
    }

    // ========================================================================
    // Multi-Operation Methods
    // ========================================================================

    public function getMultiple(iterable $keys): iterable
    {
        $keys = $this->validateAndNormalizeKeys($keys);
        $result = [];
        $remainingKeys = $keys;

        // Check each layer progressively
        foreach ($this->layers as $layerIndex => $layer) {
            if (empty($remainingKeys) || !$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                $layerResults = $this->executeWithTimeout(
                    fn () => $layer->getMultiple($remainingKeys),
                    self::LAYER_OPERATION_TIMEOUT_MS * 2
                );

                foreach ($layerResults as $key => $value) {
                    if (null !== $value) {
                        $result[$key] = $value;
                        $remainingKeys = array_diff($remainingKeys, [$key]);

                        // Promote successful hits to faster layers
                        $this->promoteToFasterLayers($key, $value, $layerIndex);
                    }
                }

                $this->recordLayerSuccess($layerIndex);
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'getMultiple', implode(',', $remainingKeys));
            }
        }

        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $expiration = null): bool
    {
        $values = $this->validateAndNormalizeValues($values);
        $resolvedExpiration = $this->resolveExpiration('batch_operation', $expiration);
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($this->executeWithTimeout(
                    fn () => $layer->setMultiple($values, $resolvedExpiration),
                    self::LAYER_OPERATION_TIMEOUT_MS * 3
                )) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'setMultiple', 'batch');
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->validateAndNormalizeKeys($keys);
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($this->executeWithTimeout(
                    fn () => $layer->deleteMultiple($keys),
                    self::LAYER_OPERATION_TIMEOUT_MS * 2
                )) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'deleteMultiple', implode(',', $keys));
            }
        }

        return $success;
    }

    // ========================================================================
    // Tag-based Operations (TaggableCacheDriver Interface)
    // ========================================================================

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $normalizedTags = $this->normalizeTags($tags);

        $resolvedExpiration = $this->resolveExpiration($key);
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                $layerSuccess = $layer instanceof TaggableCacheDriver
                    ? $this->executeWithTimeout(
                        fn () => $layer->setWithTags($key, $value, $normalizedTags),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    )
                    : $this->executeWithTimeout(
                        fn () => $layer->set($key, $value, $resolvedExpiration),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    );

                if ($layerSuccess) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'setWithTags', $key);
            }
        }

        return $success;
    }

    public function invalidateTags(iterable $tags): bool
    {
        if (empty($tags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        $normalizedTags = $this->normalizeTags($tags);
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($layer instanceof TaggableCacheDriver) {
                    $layerSuccess = $this->executeWithTimeout(
                        fn () => $layer->invalidateTags($normalizedTags),
                        self::LAYER_OPERATION_TIMEOUT_MS * 2
                    );
                } else {
                    // Fallback: clear entire layer if tags not supported
                    $layerSuccess = $this->executeWithTimeout(
                        fn () => $layer->clear(),
                        self::LAYER_OPERATION_TIMEOUT_MS * 10
                    );

                    $this->logger->warning('Layer does not support tags, performing full clear', [
                        'layer' => $layerIndex,
                        'layer_type' => $this->getLayerType($layerIndex),
                    ]);
                }

                if ($layerSuccess) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'invalidateTags', 'tags');
            }
        }

        return $success;
    }

    public function getByTags(iterable $tags): iterable
    {
        if (empty($tags)) {
            return [];
        }

        $normalizedTags = $this->normalizeTags($tags);
        $allResults = [];

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($layer instanceof TaggableCacheDriver) {
                    $layerResults = $this->executeWithTimeout(
                        fn () => $layer->getByTags($normalizedTags),
                        self::LAYER_OPERATION_TIMEOUT_MS * 2
                    );

                    if (is_iterable($layerResults)) {
                        foreach ($layerResults as $key => $value) {
                            if (!isset($allResults[$key])) {
                                $allResults[$key] = $value;

                                // Promote to faster layers
                                $this->promoteToFasterLayers($key, $value, $layerIndex);
                            }
                        }
                    }

                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'getByTags', 'tags');
            }
        }

        return $allResults;
    }

    public function getTags(): iterable
    {
        $allTags = [];

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($layer instanceof TaggableCacheDriver) {
                    $layerTags = $this->executeWithTimeout(
                        fn () => $layer->getTags(),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    );

                    if (is_iterable($layerTags)) {
                        foreach ($layerTags as $tag) {
                            $tagString = (string) $tag;
                            if (!in_array($tagString, $allTags, true)) {
                                $allTags[] = $tagString;
                            }
                        }
                    }

                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'getTags', 'all');
            }
        }

        return array_map(fn (string $tag) => new Tag($tag), $allTags);
    }

    // ========================================================================
    // Atomic Operations (AtomicOperable Interface)
    // ========================================================================

    public function increment(string $key, int $step = 1, int $initialValue = 0, mixed $ttl = null): int
    {
        $this->validateKey($key);

        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Step cannot be zero');
        }

        $resolvedExpiration = $this->resolveExpiration($key, $ttl);

        // Try atomic increment on capable layers, fallback to read-modify-write
        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                if ($layer instanceof AtomicOperable) {
                    $result = $this->executeWithTimeout(
                        fn () => $layer->increment($key, $step, $initialValue, $resolvedExpiration),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    );

                    $this->recordLayerSuccess($layerIndex);

                    // Update other layers asynchronously
                    $this->propagateAtomicResult($key, $result, $layerIndex, $resolvedExpiration);

                    return $result;
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'increment', $key);
            }
        }

        // Fallback to non-atomic implementation
        return $this->nonAtomicIncrement($key, $step, $initialValue, $resolvedExpiration);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, mixed $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // Check if key exists first
        if ($this->has($key)) {
            return false;
        }

        $resolvedExpiration = $this->resolveExpiration($key, $ttl);
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                $layerSuccess = $layer instanceof AtomicOperable
                    ? $this->executeWithTimeout(
                        fn () => $layer->add($key, $value, $resolvedExpiration),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    )
                    : $this->executeWithTimeout(
                        fn () => !$layer->has($key) && $layer->set($key, $value, $resolvedExpiration),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    );

                if ($layerSuccess) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'add', $key);
            }
        }

        return $success;
    }

    // ========================================================================
    // Compare and Swap Operations (CompareAndSwapAware Interface)
    // ========================================================================

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $resolvedExpiration = $this->resolveExpiration($key, $ttl);
        $success = false;

        foreach ($this->layers as $layerIndex => $layer) {
            if (!$this->isLayerAvailable($layerIndex)) {
                continue;
            }

            try {
                $layerSuccess = $layer instanceof CompareAndSwapAware
                    ? $this->executeWithTimeout(
                        fn () => $layer->compareAndSwap($key, $expected, $value, $resolvedExpiration),
                        self::LAYER_OPERATION_TIMEOUT_MS
                    )
                    : $this->nonAtomicCompareAndSwap($layer, $key, $expected, $value, $resolvedExpiration);

                if ($layerSuccess) {
                    $success = true;
                    $this->recordLayerSuccess($layerIndex);

                    // Update other layers with new value
                    $this->propagateCompareAndSwapResult($key, $value, $layerIndex, $resolvedExpiration);

                    break; // CAS should only succeed once
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($layerIndex, $exception, 'compareAndSwap', $key);
            }
        }

        return $success;
    }

    // ========================================================================
    // Maintenance Operations (Maintainable Interface)
    // ========================================================================

    public function maintenance(array $options = []): bool
    {
        $this->logger->info('Starting layered cache maintenance', ['options' => $options]);

        $overallSuccess = true;
        $maintenanceResults = [];

        foreach ($this->layers as $layerIndex => $layer) {
            try {
                $layerSuccess = $layer instanceof Maintainable
                    ? $layer->maintenance($options)
                    : true; // Consider non-maintainable layers as successful

                $maintenanceResults[$layerIndex] = [
                    'success' => $layerSuccess,
                    'layer_type' => $this->getLayerType($layerIndex),
                ];

                if (!$layerSuccess) {
                    $overallSuccess = false;
                }

                // Reset circuit breaker on successful maintenance
                if ($layerSuccess && isset($this->circuitBreakers[$layerIndex])) {
                    $this->circuitBreakers[$layerIndex]['failures'] = 0;
                    $this->circuitBreakers[$layerIndex]['last_failure'] = null;
                }
            } catch (\Throwable $exception) {
                $overallSuccess = false;
                $maintenanceResults[$layerIndex] = [
                    'success' => false,
                    'error' => $exception->getMessage(),
                    'layer_type' => $this->getLayerType($layerIndex),
                ];

                $this->logger->error('Layer maintenance failed', [
                    'layer' => $layerIndex,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Cleanup performance metrics if requested
        if ($options['reset_metrics'] ?? false) {
            $this->resetPerformanceMetrics();
        }

        $this->logger->info('Layered cache maintenance completed', [
            'overall_success' => $overallSuccess,
            'results' => $maintenanceResults,
        ]);

        return $overallSuccess;
    }

    // ========================================================================
    // Driver Information and Statistics
    // ========================================================================

    public function getCapabilities(): array
    {
        $capabilities = [];

        // Base capabilities always supported
        $capabilities[] = CacheCapabilities::MAINTENANCE;

        // Check if any layer supports advanced capabilities
        foreach ($this->layers as $layer) {
            $layerCapabilities = $layer->getCapabilities();

            if (in_array(CacheCapabilities::ATOMIC, $layerCapabilities, true)
                && !in_array(CacheCapabilities::ATOMIC, $capabilities, true)) {
                $capabilities[] = CacheCapabilities::ATOMIC;
            }

            if (in_array(CacheCapabilities::TAGGING, $layerCapabilities, true)
                && !in_array(CacheCapabilities::TAGGING, $capabilities, true)) {
                $capabilities[] = CacheCapabilities::TAGGING;
            }
        }

        return $capabilities;
    }

    public function getStats(): DriverStats
    {
        $aggregatedStats = $this->collectAggregatedStats();

        return new MetricsSnapshot(
            driverName: 'LayeredDriver',
            memoryUsage: $aggregatedStats['total_memory_usage'],
            itemCount: $aggregatedStats['total_item_count'],
            driverVersion: '2.0.0',
            rawStats: $aggregatedStats
        );
    }

    public function isHealthy(): bool
    {
        $healthyLayers = 0;

        foreach ($this->layers as $layerIndex => $layer) {
            if ($this->isLayerAvailable($layerIndex)) {
                ++$healthyLayers;
            }
        }

        // Consider healthy if at least one layer is available
        return $healthyLayers > 0;
    }

    // ========================================================================
    // Private Implementation Methods
    // ========================================================================

    /**
     * Validates layers configuration applying Fail-Fast principle.
     *
     * @param array<CacheDriver> $layers
     *
     * @throws ConfigurationException
     */
    private function validateLayersConfiguration(array $layers): void
    {
        if (empty($layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be provided');
        }

        foreach ($layers as $index => $layer) {
            if (!$layer instanceof CacheDriver) {
                throw ConfigurationException::invalidConfiguration("layers[{$index}]", 'Layer must implement CacheDriver interface');
            }
        }

        if (count($layers) > 10) {
            throw ConfigurationException::invalidConfiguration('layers', 'Maximum of 10 layers supported for performance reasons');
        }
    }

    /**
     * Initializes circuit breakers for all layers.
     */
    private function initializeCircuitBreakers(): void
    {
        foreach ($this->layers as $index => $layer) {
            $this->circuitBreakers[$index] = [
                'failures' => 0,
                'last_failure' => null,
                'state' => 'closed', // closed, open, half-open
            ];
        }
    }

    /**
     * Checks if a layer is available (not circuit broken).
     */
    private function isLayerAvailable(int $layerIndex): bool
    {
        if (!isset($this->circuitBreakers[$layerIndex])) {
            return false;
        }

        $breaker = &$this->circuitBreakers[$layerIndex];

        if ('closed' === $breaker['state']) {
            return true;
        }

        if ('open' === $breaker['state']) {
            $timeSinceFailure = time() - ($breaker['last_failure'] ?? 0);

            if ($timeSinceFailure >= self::CIRCUIT_BREAKER_TIMEOUT_SECONDS) {
                $breaker['state'] = 'half-open';
                $this->logger->info('Circuit breaker entering half-open state', [
                    'layer' => $layerIndex,
                ]);

                return true;
            }

            return false;
        }

        // half-open state: allow one attempt
        return true;
    }

    /**
     * Records successful layer operation and manages circuit breaker.
     */
    private function recordLayerSuccess(int $layerIndex): void
    {
        if (isset($this->circuitBreakers[$layerIndex])) {
            $breaker = &$this->circuitBreakers[$layerIndex];

            if ('half-open' === $breaker['state']) {
                $breaker['state'] = 'closed';
                $breaker['failures'] = 0;
                $this->logger->info('Circuit breaker closed after successful operation', [
                    'layer' => $layerIndex,
                ]);
            }
        }
    }

    /**
     * Handles layer failure and manages circuit breaker state.
     */
    private function handleLayerFailure(int $layerIndex, \Throwable $exception, string $operation, string $key): void
    {
        ++$this->performanceMetrics['failures'];

        if (isset($this->circuitBreakers[$layerIndex])) {
            $breaker = &$this->circuitBreakers[$layerIndex];
            ++$breaker['failures'];
            $breaker['last_failure'] = time();

            if ($breaker['failures'] >= self::MAX_FAILURES_BEFORE_CIRCUIT_BREAK) {
                $breaker['state'] = 'open';
                ++$this->performanceMetrics['circuit_breaker_trips'];

                $this->logger->warning('Circuit breaker opened due to failures', [
                    'layer' => $layerIndex,
                    'failures' => $breaker['failures'],
                    'layer_type' => $this->getLayerType($layerIndex),
                ]);
            }
        }

        $this->logger->error('Layer operation failed', [
            'layer' => $layerIndex,
            'operation' => $operation,
            'key' => $key,
            'error' => $exception->getMessage(),
            'layer_type' => $this->getLayerType($layerIndex),
        ]);
    }

    /**
     * Promotes cache item to faster layers for performance optimization.
     */
    private function promoteToFasterLayers(string $key, mixed $value, int $sourceLayerIndex): void
    {
        if (0 === $sourceLayerIndex) {
            return; // Already in fastest layer
        }

        ++$this->performanceMetrics['promotions'];

        // Promote to all faster layers asynchronously to avoid blocking main operation
        for ($i = 0; $i < $sourceLayerIndex; ++$i) {
            if (!$this->isLayerAvailable($i)) {
                continue;
            }

            try {
                $expiration = $this->resolveExpiration($key);
                $this->layers[$i]->set($key, $value, $expiration);
            } catch (\Throwable $exception) {
                // Log promotion failure but don't fail the main operation
                $this->logger->debug('Cache promotion failed', [
                    'key' => $key,
                    'target_layer' => $i,
                    'source_layer' => $sourceLayerIndex,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Executes operation with timeout protection.
     */
    private function executeWithTimeout(callable $operation, int $timeoutMs): mixed
    {
        $startTime = microtime(true);
        $result = $operation();
        $executionTime = (microtime(true) - $startTime) * 1000;

        if ($executionTime > $timeoutMs) {
            $this->logger->warning('Layer operation exceeded timeout', [
                'execution_time_ms' => $executionTime,
                'timeout_ms' => $timeoutMs,
            ]);
        }

        return $result;
    }

    /**
     * Resolves expiration using shared ExpirationProvider.
     */
    private function resolveExpiration(string $key, \DateInterval|int|null $expiration = null): ?\DateTimeInterface
    {
        if ($expiration instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($expiration);
        }

        if (is_int($expiration)) {
            return $expiration > 0 ? new \DateTimeImmutable("+{$expiration} seconds") : null;
        }

        // Use ExpirationProvider for consistent TTL management across layers
        return $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );
    }

    /**
     * Collects aggregated statistics from all layers.
     */
    private function collectAggregatedStats(): array
    {
        $stats = [
            'layer_count' => count($this->layers),
            'available_layers' => 0,
            'total_memory_usage' => 0,
            'total_item_count' => 0,
            'layer_stats' => [],
            'performance_metrics' => $this->performanceMetrics,
            'circuit_breakers' => $this->circuitBreakers,
        ];

        foreach ($this->layers as $index => $layer) {
            $layerStats = [
                'type' => $this->getLayerType($index),
                'available' => $this->isLayerAvailable($index),
                'capabilities' => $layer->getCapabilities(),
            ];

            if ($this->isLayerAvailable($index)) {
                ++$stats['available_layers'];

                try {
                    if (method_exists($layer, 'getStats')) {
                        $driverStats = $layer->getStats();
                        $layerStats['memory_usage'] = $driverStats->getMemoryUsage();
                        $layerStats['item_count'] = $driverStats->getItemCount();
                        $layerStats['driver_version'] = $driverStats->getDriverVersion();

                        $stats['total_memory_usage'] += $layerStats['memory_usage'] ?? 0;
                        $stats['total_item_count'] += $layerStats['item_count'] ?? 0;
                    }
                } catch (\Throwable $exception) {
                    $layerStats['stats_error'] = $exception->getMessage();
                }
            }

            $stats['layer_stats'][$index] = $layerStats;
        }

        return $stats;
    }

    /**
     * Validation and normalization helper methods.
     */
    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > 250) {
            throw InvalidArgumentException::keyTooLong($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        if (is_resource($value)) {
            throw InvalidArgumentException::invalidValue('Resources cannot be cached');
        }
    }

    /**
     * @param iterable<string> $keys
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
     * @param iterable<string, mixed> $values
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
     * @param iterable<string|Tag> $tags
     *
     * @return array<Tag>
     */
    private function normalizeTags(iterable $tags): array
    {
        $normalizedTags = [];

        foreach ($tags as $tag) {
            if ($tag instanceof Tag) {
                $normalizedTags[] = $tag;
            } elseif (is_string($tag)) {
                $normalizedTags[] = new Tag($tag);
            } else {
                throw InvalidArgumentException::invalidTag('Tags must be strings or Tag objects');
            }
        }

        if (empty($normalizedTags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        return $normalizedTags;
    }

    /**
     * Helper methods for layer information.
     */
    private function getLayerType(int $index): string
    {
        if (!isset($this->layers[$index])) {
            return 'unknown';
        }

        return get_class($this->layers[$index]);
    }

    /**
     * @return array<string>
     */
    private function getLayerTypes(): array
    {
        return array_map(fn ($layer) => get_class($layer), $this->layers);
    }

    /**
     * Non-atomic increment fallback for layers without atomic support.
     */
    private function nonAtomicIncrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $currentValue = $this->get($key);
        $newValue = is_numeric($currentValue) ? (int) $currentValue + $step : $initialValue + $step;

        $this->set($key, $newValue, $expiration);

        return $newValue;
    }

    /**
     * Non-atomic compare and swap fallback.
     */
    private function nonAtomicCompareAndSwap(
        CacheDriver $layer,
        string $key,
        mixed $expected,
        mixed $value,
        ?\DateTimeInterface $expiration
    ): bool {
        $current = $layer->get($key);

        if ($current === $expected) {
            return $layer->set($key, $value, $expiration);
        }

        return false;
    }

    /**
     * Propagate atomic operation results to other layers.
     */
    private function propagateAtomicResult(string $key, int $result, int $sourceLayer, ?\DateTimeInterface $expiration): void
    {
        foreach ($this->layers as $index => $layer) {
            if ($index === $sourceLayer || !$this->isLayerAvailable($index)) {
                continue;
            }

            try {
                $layer->set($key, $result, $expiration);
            } catch (\Throwable $exception) {
                $this->logger->debug('Failed to propagate atomic result', [
                    'key' => $key,
                    'target_layer' => $index,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Propagate compare-and-swap results to other layers.
     */
    private function propagateCompareAndSwapResult(string $key, mixed $value, int $sourceLayer, ?\DateTimeInterface $expiration): void
    {
        foreach ($this->layers as $index => $layer) {
            if ($index === $sourceLayer || !$this->isLayerAvailable($index)) {
                continue;
            }

            try {
                $layer->set($key, $value, $expiration);
            } catch (\Throwable $exception) {
                $this->logger->debug('Failed to propagate CAS result', [
                    'key' => $key,
                    'target_layer' => $index,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resets performance metrics.
     */
    private function resetPerformanceMetrics(): void
    {
        $this->performanceMetrics = [
            'hits' => 0,
            'misses' => 0,
            'promotions' => 0,
            'failures' => 0,
            'circuit_breaker_trips' => 0,
        ];
    }
}
