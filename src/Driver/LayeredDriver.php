<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\Driver\LayeredDriver as LayeredDriverContract;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCache;
use KaririCode\Cache\Driver\Layered\LayeredAtomicOperations;
use KaririCode\Cache\Driver\Layered\LayeredMaintenanceHandler;
use KaririCode\Cache\Driver\Layered\LayeredTagOperations;
use KaririCode\Cache\Driver\Layered\LayeredTopicOperations;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\CircuitBreakerState;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates a prioritized hierarchy of cache drivers (layers).
 *
 * This class acts as a sophisticated Facade, implementing all primary cache
 * interfaces (PSR-6, PSR-16, Taggable, Atomic, etc.). Internally, it delegates
 * all operations to specialized, layered handlers. This design adheres to the
 * Single Responsibility Principle at a component level while providing a unified
 * entry point. It includes advanced strategies like cache promotion and a
 * Circuit Breaker to automatically bypass failing layers, ensuring resilience.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredDriver implements LayeredDriverContract
{
    /** @var array<int, CacheItemPoolInterface> */
    private readonly array $layers;

    /** @var array<int, CircuitBreakerState> */
    private array $circuitBreakers = [];

    /** @var array<string, int> */
    private array $hitCounts = [];

    private readonly ValidationService $validator;

    // Handlers for different capabilities
    private ?LayeredTagOperations $tagHandler = null;
    private ?LayeredAtomicOperations $atomicHandler = null;
    private ?LayeredTopicOperations $topicHandler = null;
    private ?LayeredMaintenanceHandler $maintenanceHandler = null;

    public function __construct(
        array $layers,
        private readonly LayeredDriverConfig $config,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        if (empty($layers)) {
            throw ConfigurationException::invalidConfiguration('layers', 'At least one cache layer must be provided.');
        }
        $this->layers = array_values($layers); // Ensure sequential indexing
        $this->initializeCircuitBreakers();
        $this->initializeCapabilityHandlers();
        $this->validator = new ValidationService();
    }

    // --- PSR-6: CacheItemPoolInterface Methods ---

    public function getItem(string $key): CacheItemInterface
    {
        $this->validator->validateKey($key);

        return $this->executeReadOperation(
            'getItem',
            $key,
            fn (CacheItemPoolInterface $layer) => $layer->getItem($key),
            fn () => new CacheItem($key, null, false)
        );
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
            if (empty($remainingKeys) || !$this->isLayerAvailable($priority)) {
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

        foreach (array_keys($remainingKeys) as $key) {
            $results[$key] = new CacheItem($key, null, false);
        }

        return $results;
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
        return $this->executeWriteOperation('clear', fn (CacheItemPoolInterface $layer) => $layer->clear());
    }

    public function deleteItem(string $key): bool
    {
        $this->validator->validateKey($key);
        unset($this->hitCounts[$key]);

        return $this->executeWriteOperation('deleteItem', fn (CacheItemPoolInterface $layer) => $layer->deleteItem($key), $key);
    }

    public function deleteItems(array $keys): bool
    {
        $this->validator->validateKeys($keys);
        foreach ($keys as $key) {
            unset($this->hitCounts[$key]);
        }

        return $this->executeWriteOperation('deleteItems', fn (CacheItemPoolInterface $layer) => $layer->deleteItems($keys));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->executeWriteOperation('save', fn (CacheItemPoolInterface $layer) => $layer->save(clone $item), $item->getKey());
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }

    // --- PSR-16: SimpleCache Methods (Delegated) ---

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->getItem($key);

        return $item->isHit() ? $item->get() : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $item = new CacheItem($key, $value);
        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }

        return $this->save($item);
    }

    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $items = $this->getItems(is_array($keys) ? $keys : iterator_to_array($keys));
        $results = [];
        foreach ($items as $key => $item) {
            $results[$key] = $item->isHit() ? $item->get() : $default;
        }

        return $results;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $item = new CacheItem((string) $key, $value);
            if (null !== $ttl) {
                $item->expiresAfter($ttl);
            }
            $items[] = $item;
        }

        $allSuccess = true;
        foreach ($items as $item) {
            if (!$this->save($item)) {
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->deleteItems(is_array($keys) ? $keys : iterator_to_array($keys));
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    // --- Atomic Operations (Delegated to Handler) ---

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->atomicHandler ? $this->atomicHandler->increment($key, $step, $initialValue) : $this->nonAtomicIncrement($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->increment($key, -$step, $initialValue);
    }

    public function add(string $key, mixed $value): bool
    {
        return $this->atomicHandler ? $this->atomicHandler->add($key, $value) : $this->nonAtomicAdd($key, $value);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        return $this->atomicHandler ? $this->atomicHandler->compareAndSwap($key, $expected, $value) : false;
    }

    // --- Tag Operations (Delegated to Handler) ---

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        return $this->tagHandler ? $this->tagHandler->setWithTags($key, $value, $tags) : false;
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagHandler ? $this->tagHandler->invalidateTags($tags) : false;
    }

    public function getByTags(iterable $tags): iterable
    {
        return $this->tagHandler ? $this->tagHandler->getByTags($tags) : [];
    }

    public function getTags(): iterable
    {
        return $this->tagHandler ? $this->tagHandler->getTags() : [];
    }

    // --- Topic Operations (Delegated to Handler) ---

    public function subscribeTo(string $key, iterable $topics): void
    {
        if ($this->topicHandler) {
            $this->topicHandler->subscribeTo($key, $topics);
        }
    }

    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        if ($this->topicHandler) {
            $this->topicHandler->unsubscribeFrom($key, $topics);
        }
    }

    public function invalidateTopics(iterable $topics): bool
    {
        return $this->topicHandler ? $this->topicHandler->invalidateTopics($topics) : false;
    }

    public function getSubscribedTopics(string $key): array
    {
        return $this->topicHandler ? $this->topicHandler->getSubscribedTopics($key) : [];
    }

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topicHandler ? $this->topicHandler->getSubscribersForTopic($topic) : [];
    }

    // --- Maintenance (Delegated to Handler) ---

    public function maintenance(array $options = []): bool
    {
        return $this->maintenanceHandler ? $this->maintenanceHandler->maintenance($options) : true;
    }

    // --- Driver Metadata ---

    public function getCapabilities(): array
    {
        $capabilities = [];
        if ($this->atomicHandler) {
            $capabilities[] = CacheCapabilities::ATOMIC_OPERATIONS;
        }
        if ($this->tagHandler) {
            $capabilities[] = CacheCapabilities::TAGGING_OPERATIONS;
        }
        if ($this->topicHandler) {
            $capabilities[] = CacheCapabilities::TOPICS_OPERATIONS;
        }
        if ($this->maintenanceHandler) {
            $capabilities[] = CacheCapabilities::MAINTENANCE_OPERATIONS;
        }

        return $capabilities;
    }

    public function getStats(): DriverStats
    {
        $firstLayer = $this->layers[array_key_first($this->layers)] ?? null;
        if ($firstLayer instanceof CacheDriver) {
            return $firstLayer->getStats();
        }

        return new \KaririCode\Cache\ValueObject\MetricsSnapshot('LayeredDriver', 0, 0, '1.0', []);
    }

    // --- Public Methods for Handlers ---

    public function isLayerAvailable(int $priority): bool
    {
        if (!$this->config->circuitBreakerEnabled) {
            return true;
        }
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

    public function handleFailure(int $priority, \Throwable $e, string $operation, ?string $key = 'N/A'): void
    {
        if (!$this->config->circuitBreakerEnabled) {
            $this->logger->error('Cache layer operation failed.', ['priority' => $priority, 'operation' => $operation, 'key' => $key, 'error' => $e->getMessage()]);

            return;
        }
        $breaker = $this->circuitBreakers[$priority]->recordFailure();
        if ($breaker->shouldOpen($this->config->maxFailures)) {
            $breaker = $breaker->open();
            $this->logger->critical('Circuit breaker opened for cache layer.', ['priority' => $priority, 'layer' => get_class($this->layers[$priority])]);
        }
        $this->circuitBreakers[$priority] = $breaker;
        $this->logger->error('Cache layer operation failed.', ['priority' => $priority, 'operation' => $operation, 'key' => $key, 'error' => $e->getMessage()]);
    }

    public function recordSuccess(int $priority): void
    {
        if (!$this->config->circuitBreakerEnabled) {
            return;
        }
        $this->circuitBreakers[$priority] = $this->circuitBreakers[$priority]->recordSuccess();
    }

    public function propagateValueToAllLayers(string $key, mixed $value, int $sourcePriority): void
    {
        $item = new CacheItem($key, $value);
        $this->executeWriteOperation(
            'propagateValue',
            function (CacheItemPoolInterface $layer) use ($item, $sourcePriority) {
                if (spl_object_id($layer) !== spl_object_id($this->layers[$sourcePriority])) {
                    return $layer->save(clone $item);
                }

                return true;
            },
            $key
        );
    }

    public function nonAtomicIncrement(string $key, int $step, int $initialValue): int
    {
        $item = $this->getItem($key);
        $currentValue = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : $initialValue;
        $newValue = $currentValue + $step;
        $this->save($item->set($newValue));

        return $newValue;
    }

    public function nonAtomicAdd(string $key, mixed $value): bool
    {
        if ($this->hasItem($key)) {
            return false;
        }

        return $this->save(new CacheItem($key, $value));
    }

    // --- Private Methods ---

    private function initializeCapabilityHandlers(): void
    {
        $taggableLayers = array_filter($this->layers, fn ($l) => $l instanceof TaggableCache);
        if (!empty($taggableLayers)) {
            $this->tagHandler = new LayeredTagOperations($taggableLayers, $this);
        }

        $atomicLayers = array_filter($this->layers, fn ($l) => $l instanceof AtomicOperable);
        if (!empty($atomicLayers)) {
            $this->atomicHandler = new LayeredAtomicOperations($atomicLayers, $this);
        }

        $topicLayers = array_filter($this->layers, fn ($l) => $l instanceof TopicSubscribable);
        if (!empty($topicLayers)) {
            $this->topicHandler = new LayeredTopicOperations($topicLayers, $this);
        }

        $maintainableLayers = array_filter($this->layers, fn ($l) => $l instanceof Maintainable);
        if (!empty($maintainableLayers)) {
            $this->maintenanceHandler = new LayeredMaintenanceHandler($maintainableLayers, $this);
        }
    }

    private function executeReadOperation(string $operationName, string $key, callable $operation, callable $defaultValue): mixed
    {
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                $result = $operation($layer);
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

    private function initializeCircuitBreakers(): void
    {
        foreach (array_keys($this->layers) as $priority) {
            $this->circuitBreakers[$priority] = new CircuitBreakerState();
        }
    }

    private function promoteToHigherLayers(CacheItemInterface $item, int $sourcePriority): void
    {
        if (!$this->config->promotionEnabled) {
            return;
        }

        $key = $item->getKey();
        $this->hitCounts[$key] = ($this->hitCounts[$key] ?? 0) + 1;
        if ($this->hitCounts[$key] < $this->config->promotionThreshold) {
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
}
