<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\CircuitBreakerState;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages a prioritized hierarchy of cache pools (layers).
 *
 * This driver implements the Chain of Responsibility and Composite patterns to
 * orchestrate multiple cache layers. It includes advanced strategies like cache
 * promotion (moving hot data to faster layers) and a Circuit Breaker to
 * automatically bypass failing layers, ensuring resilience.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredDriver implements CacheDriver
{
    /** @var array<int, CacheItemPoolInterface> */
    private readonly array $layers;

    /** @var array<int, CircuitBreakerState> */
    private array $circuitBreakers = [];

    /** @var array<string, int> */
    private array $hitCounts = [];

    private readonly ValidationService $validator;

    private const PROMOTION_THRESHOLD = 3;

    public function __construct(
        array $layers,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        if (empty($layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be provided.');
        }
        $this->layers = array_values($layers); // Ensure sequential indexing
        $this->initializeCircuitBreakers();
        $this->validator = new ValidationService();
    }

    // --- PSR-6 CacheItemPoolInterface Methods ---

    public function getItem(string $key): CacheItemInterface
    {
        $this->validator->validateKey($key);

        return $this->executeReadOperation(
            'getItem',
            $key,
            fn (CacheItemPoolInterface $layer) => $layer->getItem($key),
            fn () => new CacheItem($key, null, false) // Default value on total failure
        );
    }

    public function get(string $key): mixed
    {
        $item = $this->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    public function set(string $key, mixed $value): bool
    {
        $item = new CacheItem($key, $value);

        return $this->save($item);
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    public function getMultiple(iterable $keys): iterable
    {
        $items = $this->getItems($keys);
        $results = [];
        foreach ($items as $key => $item) {
            $results[$key] = $item->isHit() ? $item->get() : null;
        }

        return $results;
    }

    public function getItems(iterable $keys = []): iterable
    {
        $this->validator->validateKeys($keys);
        if (empty($keys)) {
            return [];
        }

        $results = [];
        $remainingKeys = array_flip($keys);

        foreach ($this->layers as $priority => $layer) {
            if (empty($remainingKeys)) {
                break;
            }
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $items = $layer->getItems(array_keys($remainingKeys));
                foreach ($items as $item) {
                    if ($item->isHit()) {
                        $itemKey = $item->getKey();
                        $results[$itemKey] = $item;
                        unset($remainingKeys[$itemKey]);
                        $this->promoteToHigherLayers($item, $priority);
                    }
                }
                $this->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'getItems');
            }
        }

        // For any remaining keys, return a miss.
        foreach (array_keys($remainingKeys) as $key) {
            $results[$key] = new CacheItem($key, null, false);
        }

        return $results;
    }

    public function setMultiple(iterable $values): bool
    {
        $overallSuccess = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value)) {
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->deleteItems(is_array($keys) ? $keys : iterator_to_array($keys));
    }

    public function hasItem(string $key): bool
    {
        $this->validator->validateKey($key);

        return $this->executeReadOperation(
            'hasItem',
            $key,
            fn (CacheItemPoolInterface $layer) => $layer->hasItem($key),
            fn () => false
        );
    }

    public function clear(): bool
    {
        return $this->executeWriteOperation(
            'clear',
            fn (CacheItemPoolInterface $layer) => $layer->clear()
        );
    }

    public function deleteItem(string $key): bool
    {
        $this->validator->validateKey($key);
        unset($this->hitCounts[$key]);

        return $this->executeWriteOperation(
            'deleteItem',
            fn (CacheItemPoolInterface $layer) => $layer->deleteItem($key),
            $key
        );
    }

    public function deleteItems(array $keys): bool
    {
        $this->validator->validateKeys($keys);
        foreach ($keys as $key) {
            unset($this->hitCounts[$key]);
        }

        return $this->executeWriteOperation(
            'deleteItems',
            fn (CacheItemPoolInterface $layer) => $layer->deleteItems($keys)
        );
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->executeWriteOperation(
            'save',
            fn (CacheItemPoolInterface $layer) => $layer->save(clone $item),
            $item->getKey()
        );
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        // For a layered cache, deferring is complex and risky.
        // The safest strategy is to save immediately (write-through).
        return $this->save($item);
    }

    public function commit(): bool
    {
        // Since saveDeferred is not truly deferred, commit is a no-op.
        return true;
    }

    // --- Other Interfaces (Delegated to Template Methods) ---

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $this->validator->validateKey($key);
        // Atomic operations are complex and better handled with specific logic.
        // We attempt it on the first available atomic-capable layer.
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof AtomicOperable) {
                try {
                    $newValue = $layer->increment($key, $step, $initialValue);
                    $this->propagateValueToAllLayers($key, $newValue, $priority);
                    $this->recordSuccess($priority);

                    return $newValue;
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'increment', $key);
                }
            }
        }

        // Fallback to non-atomic operation if no capable layer is found/works.
        return $this->nonAtomicIncrement($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->increment($key, -$step, $initialValue);
    }

    public function add(string $key, mixed $value): bool
    {
        $this->validator->validateKey($key);
        if ($this->hasItem($key)) {
            return false;
        }

        return $this->save(new CacheItem($key, $value));
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        // Implementation for compareAndSwap...
        return false; // Placeholder
    }

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $item = (new CacheItem($key, $value))->tag($tags);

        return $this->save($item);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->executeWriteOperation(
            'invalidateTags',
            function (CacheItemPoolInterface $layer) use ($tags) {
                return $layer instanceof TaggableCacheDriver ? $layer->invalidateTags($tags) : true;
            }
        );
    }

    public function getByTags(iterable $tags): iterable
    {
        // getByTags aggregates results, so it needs custom logic.
        $results = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TaggableCacheDriver) {
                try {
                    foreach ($layer->getByTags($tags) as $key => $value) {
                        if (!isset($results[$key])) {
                            $results[$key] = $value;
                        }
                    }
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'getByTags');
                }
            }
        }

        return $results;
    }

    public function getTags(): iterable
    {
        // getTags aggregates results, so it needs custom logic.
        $allTags = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TaggableCacheDriver) {
                try {
                    foreach ($layer->getTags() as $tag) {
                        $allTags[(string) $tag] = true;
                    }
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'getTags');
                }
            }
        }

        return array_keys($allTags);
    }

    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->executeWriteOperation(
            'subscribeTo',
            function (CacheItemPoolInterface $layer) use ($key, $topics) {
                if ($layer instanceof TopicAwareCacheDriver) {
                    $layer->subscribeTo($key, $topics);
                }

                return true;
            },
            $key
        );
    }

    public function invalidateTopics(iterable $topics): bool
    {
        return $this->executeWriteOperation(
            'invalidateTopics',
            function (CacheItemPoolInterface $layer) use ($topics) {
                return $layer instanceof TopicAwareCacheDriver ? $layer->invalidateTopics($topics) : true;
            }
        );
    }

    public function getSubscribedTopics(string $key): array
    {
        // Aggregates results
        $allTopics = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicAwareCacheDriver) {
                try {
                    $allTopics = array_merge($allTopics, $layer->getSubscribedTopics($key));
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'getSubscribedTopics', $key);
                }
            }
        }

        return array_unique($allTopics);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        // Aggregates results
        $allSubscribers = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicAwareCacheDriver) {
                try {
                    $allSubscribers = array_merge($allSubscribers, $layer->getSubscribersForTopic($topic));
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'getSubscribersForTopic', $topic);
                }
            }
        }

        return array_unique($allSubscribers);
    }

    public function maintenance(array $options = []): bool
    {
        return $this->executeWriteOperation(
            'maintenance',
            function (CacheItemPoolInterface $layer) use ($options) {
                return $layer instanceof Maintainable ? $layer->maintenance($options) : true;
            }
        );
    }

    public function getCapabilities(): array
    {
        // Aggregates capabilities from all layers.
        $capabilities = [];
        foreach ($this->layers as $layer) {
            if ($layer instanceof CacheDriver) {
                $capabilities = array_merge($capabilities, $layer->getCapabilities());
            }
        }

        return array_unique($capabilities);
    }

    public function getStats(): DriverStats
    {
        // Stats for a layered driver would be complex; returning stats for the first layer is a reasonable default.
        $firstLayer = $this->layers[array_key_first($this->layers)] ?? null;
        if ($firstLayer && $firstLayer instanceof CacheDriver) {
            return $firstLayer->getStats();
        }

        // Return a default/empty stats object if no layer provides it.
        return new MetricsSnapshot('LayeredDriver', 0, 0, '1.0', []);
    }

    // --- Template Methods for Orchestration ---

    /**
     * Template for read operations that stop on the first success.
     *
     * @template T
     *
     * @param callable(CacheItemPoolInterface): T $operation
     * @param callable(): T $defaultValue
     *
     * @return T
     */
    private function executeReadOperation(string $operationName, string $key, callable $operation, callable $defaultValue): mixed
    {
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                $result = $operation($layer);
                // For 'getItem', the result is an object; we check its hit status.
                // For 'hasItem', the result is a boolean.
                $isHit = ($result instanceof CacheItemInterface && $result->isHit()) || (true === $result);

                if ($isHit) {
                    $this->recordSuccess($priority);
                    if ($result instanceof CacheItemInterface) {
                        $this->promoteToHigherLayers($result, $priority);
                    }

                    return $result;
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, $operationName, $key);
            }
        }

        return $defaultValue();
    }

    /**
     * Template for write operations that execute on all available layers.
     */
    private function executeWriteOperation(string $operationName, callable $operation, ?string $key = null): bool
    {
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (false === $operation($layer)) {
                    $overallSuccess = false;
                } else {
                    $this->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, $operationName, $key);
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }

    // --- Private Helper Methods ---

    private function initializeCircuitBreakers(): void
    {
        foreach (array_keys($this->layers) as $priority) {
            $this->circuitBreakers[$priority] = new CircuitBreakerState();
        }
    }

    private function isLayerAvailable(int $priority): bool
    {
        $breaker = $this->circuitBreakers[$priority];
        if ($breaker->isOpen()) {
            if ($breaker->canAttemptRetry()) {
                $this->circuitBreakers[$priority] = $breaker->halfOpen();
                $this->logger->info('Circuit breaker is now half-open.', ['priority' => $priority]);

                return true;
            }

            return false;
        }

        return true;
    }

    private function handleFailure(int $priority, \Throwable $e, string $operation, ?string $key = 'N/A'): void
    {
        $breaker = $this->circuitBreakers[$priority]->recordFailure();
        if ($breaker->shouldOpen()) {
            $breaker = $breaker->open();
            $this->logger->critical('Circuit breaker opened for cache layer.', [
                'priority' => $priority, 'layer' => get_class($this->layers[$priority]),
            ]);
        }
        $this->circuitBreakers[$priority] = $breaker;
        $this->logger->error('Cache layer operation failed.', [
            'priority' => $priority, 'operation' => $operation, 'key' => $key, 'error' => $e->getMessage(),
        ]);
    }

    private function recordSuccess(int $priority): void
    {
        $this->circuitBreakers[$priority] = $this->circuitBreakers[$priority]->recordSuccess();
    }

    private function promoteToHigherLayers(CacheItemInterface $item, int $sourcePriority): void
    {
        $key = $item->getKey();
        $this->hitCounts[$key] = ($this->hitCounts[$key] ?? 0) + 1;

        if ($this->hitCounts[$key] < self::PROMOTION_THRESHOLD) {
            return;
        }

        for ($p = 0; $p < $sourcePriority; ++$p) {
            if (isset($this->layers[$p]) && $this->isLayerAvailable($p)) {
                try {
                    $this->layers[$p]->save(clone $item);
                } catch (\Throwable $e) {
                    $this->handleFailure($p, $e, 'promotion_save', $key);
                }
            }
        }
        unset($this->hitCounts[$key]);
    }

    private function propagateValueToAllLayers(string $key, mixed $value, int $sourcePriority): void
    {
        $item = new CacheItem($key, $value);
        $this->executeWriteOperation(
            'propagateValue',
            function (CacheItemPoolInterface $layer) use ($item, $sourcePriority) {
                // Avoid writing back to the source layer
                if (spl_object_id($layer) !== spl_object_id($this->layers[$sourcePriority])) {
                    return $layer->save(clone $item);
                }

                return true;
            },
            $key
        );
    }

    private function nonAtomicIncrement(string $key, int $step, int $initialValue): int
    {
        $item = $this->getItem($key);
        $currentValue = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : $initialValue;
        $newValue = $currentValue + $step;
        $this->save($item->set($newValue));

        return $newValue;
    }
}
