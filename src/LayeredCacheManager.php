<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\Behavioral\TaggablePool;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\CircuitBreakerState;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages a prioritized hierarchy of cache pools (layers).
 *
 * Implements advanced strategies like cache promotion (moving hot data to faster layers)
 * and a Circuit Breaker pattern to automatically bypass failing layers, ensuring resilience.
 */
final class LayeredCacheManager implements LayeredCache
{
    /** @var array<int, CacheItemPoolInterface> */
    private array $layers = [];

    /** @var array<int, CircuitBreakerState> */
    private array $circuitBreakers = [];

    /** @var array<string, int> */
    private array $hitCounts = [];

    private const MAX_FAILURES = 5;
    private const CIRCUIT_BREAKER_TIMEOUT = 60; // seconds
    private const PROMOTION_THRESHOLD = 3; // hits before promotion

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ValidationService $validator = new ValidationService()
    ) {
    }

    // --- Layer Management ---

    public function addLayer(CacheItemPoolInterface $pool, int $priority): void
    {
        $this->layers[$priority] = $pool;
        $this->circuitBreakers[$priority] = new CircuitBreakerState();
        ksort($this->layers);
    }

    public function getLayers(): array
    {
        return $this->layers;
    }

    // --- PSR-6 CacheItemPoolInterface Methods ---

    public function getItem(string $key): CacheItemInterface
    {
        $this->validator->validateKey($key);
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                $item = $layer->getItem($key);
                if ($item->isHit()) {
                    $this->recordSuccess($priority);
                    $this->promoteToHigherLayers($item, $priority);

                    return $item;
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'getItem', $key);
            }
        }

        return new CacheItem($key, null, false);
    }

    public function getItems(iterable $keys = []): iterable
    {
        $this->validator->validateKeys($keys);
        $result = [];
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
                        $key = $item->getKey();
                        $result[$key] = $item;
                        unset($remainingKeys[$key]);
                        $this->promoteToHigherLayers($item, $priority);
                    }
                }
                $this->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'getItems');
            }
        }
        foreach (array_keys($remainingKeys) as $key) {
            $result[$key] = new CacheItem($key, null, false);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        $this->validator->validateKey($key);
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if ($layer->hasItem($key)) {
                    $this->recordSuccess($priority);

                    return true;
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'hasItem', $key);
            }
        }

        return false;
    }

    public function clear(): bool
    {
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->clear()) {
                    $overallSuccess = false;
                } else {
                    $this->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'clear');
                $overallSuccess = false;
            }
        }
        $this->hitCounts = [];

        return $overallSuccess;
    }

    public function deleteItem(string $key): bool
    {
        $this->validator->validateKey($key);
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->deleteItem($key)) {
                    $overallSuccess = false;
                } else {
                    $this->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'deleteItem', $key);
                $overallSuccess = false;
            }
        }
        unset($this->hitCounts[$key]);

        return $overallSuccess;
    }

    public function deleteItems(array $keys): bool
    {
        $this->validator->validateKeys($keys);
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->deleteItems($keys)) {
                    $overallSuccess = false;
                } else {
                    $this->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'deleteItems');
                $overallSuccess = false;
            }
        }
        foreach ($keys as $key) {
            unset($this->hitCounts[$key]);
        }

        return $overallSuccess;
    }

    public function save(CacheItemInterface $item): bool
    {
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->save(clone $item)) {
                    $overallSuccess = false;
                } else {
                    $this->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->handleFailure($priority, $e, 'save', $item->getKey());
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item); // Safest strategy for layered cache
    }

    public function commit(): bool
    {
        return true; // `saveDeferred` is not truly deferred, so commit is a no-op
    }

    // --- AtomicOperable Methods ---

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $this->validator->validateKey($key);
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

        $this->logger->warning('No atomic layer available for increment. Falling back to non-atomic operation.', ['key' => $key]);
        $item = $this->getItem($key);
        $currentValue = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : $initialValue;
        $newValue = $currentValue + $step;
        $this->save($item->set($newValue));

        return $newValue;
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

    // --- CompareAndSwapAware Methods ---

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        $this->validator->validateKey($key);
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof CompareAndSwapAware) {
                try {
                    if ($layer->compareAndSwap($key, $expected, $value)) {
                        $this->propagateValueToAllLayers($key, $value, $priority);
                        $this->recordSuccess($priority);

                        return true;
                    } else {
                        // If CAS fails on an authoritative layer, the whole operation fails.
                        return false;
                    }
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'compareAndSwap', $key);
                }
            }
        }
        $this->logger->error('No layer supports CompareAndSwap operation.', ['key' => $key]);

        return false;
    }

    // --- TaggablePool & Taggable Methods ---

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $item = new CacheItem($key, $value);
        $item->tag($tags);

        return $this->save($item);
    }

    public function invalidateTags(iterable $tags): bool
    {
        $uniqueInvalidatedKeys = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TaggablePool) {
                try {
                    $keysBefore = $layer->getByTags($tags);
                    if (is_array($keysBefore)) {
                        $uniqueInvalidatedKeys = array_merge($uniqueInvalidatedKeys, array_keys($keysBefore));
                    }
                    $layer->invalidateTags($tags);
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'invalidateTags');
                }
            }
        }

        return count(array_unique($uniqueInvalidatedKeys)) > 1;
    }

    public function getByTags(iterable $tags): iterable
    {
        $results = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TaggablePool) {
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
        $allTags = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TaggablePool) {
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

    // --- TopicSubscribable Methods ---

    public function subscribeTo(string $key, iterable $topics): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    $layer->subscribeTo($key, $topics);
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'subscribeTo', $key);
                }
            }
        }
    }

    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    $layer->unsubscribeFrom($key, $topics);
                    $this->recordSuccess($priority);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'unsubscribeFrom', $key);
                }
            }
        }
    }

    public function invalidateTopics(iterable $topics): bool
    {
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    if (!$layer->invalidateTopics($topics)) {
                        $overallSuccess = false;
                    } else {
                        $this->recordSuccess($priority);
                    }
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'invalidateTopics');
                    $overallSuccess = false;
                }
            }
        }

        return $overallSuccess;
    }

    public function getSubscribedTopics(string $key): array
    {
        $allTopics = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
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
        $allSubscribers = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
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

    // --- Maintainable Method ---

    public function maintenance(array $options = []): bool
    {
        $overallSuccess = true;
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof Maintainable) {
                try {
                    if (!$layer->maintenance($options)) {
                        $overallSuccess = false;
                    } else {
                        $this->recordSuccess($priority);
                    }
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'maintenance');
                    $overallSuccess = false;
                }
            }
        }

        return $overallSuccess;
    }

    // --- Private Helper Methods ---

    private function isLayerAvailable(int $priority): bool
    {
        $breaker = $this->circuitBreakers[$priority];
        if (CircuitBreakerState::STATE_OPEN === $breaker->state) {
            if (time() - ($breaker->lastFailureTimestamp ?? 0) > self::CIRCUIT_BREAKER_TIMEOUT) {
                $this->circuitBreakers[$priority] = $breaker->halfOpenCircuit();
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
        if ($breaker->failures >= self::MAX_FAILURES && CircuitBreakerState::STATE_CLOSED === $breaker->state) {
            $breaker = $breaker->openCircuit();
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
        foreach ($this->layers as $priority => $layer) {
            if ($priority !== $sourcePriority && $this->isLayerAvailable($priority)) {
                try {
                    $layer->save(clone $item);
                } catch (\Throwable $e) {
                    $this->handleFailure($priority, $e, 'propagate_value', $key);
                }
            }
        }
    }
}
