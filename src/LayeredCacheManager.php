<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\Behavioral\TaggablePool;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Contract\CacheItem as CacheItemContract;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\InputNormalizationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LayeredCacheManager implements LayeredCache
{
    use InputNormalizationTrait;
    use CacheKeyValidationTrait;
    use TagValidationTrait;
    use TopicValidationTrait;

    /** @var array<int, CacheItemPool> Ordered cache layers by priority */
    private array $layers = [];

    /** @var array<int, array{failures: int, last_failure: float|null, state: string}> Circuit breaker state per layer */
    private array $circuitBreakers = [];

    private readonly LoggerInterface $logger;

    private const MAX_FAILURES_BEFORE_CIRCUIT_BREAK = 3;
    private const CIRCUIT_BREAKER_TIMEOUT_SECONDS = 30;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addLayer(CacheItemPool $pool, int $priority = 100): void
    {
        $this->layers[$priority] = $pool;
        $this->initializeCircuitBreaker($priority);
        ksort($this->layers);

        $this->logger->debug('Cache layer added', [
            'priority' => $priority,
            'layer_type' => get_class($pool),
            'total_layers' => count($this->layers),
        ]);
    }

    public function getLayers(): array
    {
        return $this->layers;
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);

        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                $item = $layer->getItem($key);
                if ($item->isHit()) {
                    $this->recordLayerSuccess($priority);
                    $this->promoteToHigherLayers($key, $item, $priority);
                    $this->logger->debug('Cache hit from layer', ['key' => $key, 'layer_priority' => $priority]);

                    return $item instanceof CacheItemContract ? $item : $this->adaptToCacheItem($item);
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'getItem', $key);
            }
        }

        return new CacheItem($key);
    }

    public function getItems(iterable $keys = []): iterable
    {
        $keys = $this->validateAndNormalizeKeys($keys);
        $result = [];
        $remainingKeys = $keys;

        foreach ($this->layers as $priority => $layer) {
            if (empty($remainingKeys) || !$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                $layerItems = $layer->getItems($remainingKeys);
                foreach ($layerItems as $key => $item) {
                    if ($item->isHit()) {
                        $result[$key] = $item instanceof CacheItemContract ? $item : $this->adaptToCacheItem($item);
                        $remainingKeys = array_diff($remainingKeys, [$key]);
                        $this->promoteToHigherLayers($key, $item, $priority);
                    }
                }
                $this->recordLayerSuccess($priority);
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'getItems', implode(',', $remainingKeys));
            }
        }

        foreach ($remainingKeys as $key) {
            $result[$key] = new CacheItem($key);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if ($layer->hasItem($key)) {
                    $this->recordLayerSuccess($priority);

                    return true;
                }
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'hasItem', $key);
            }
        }

        return false;
    }

    public function clear(): bool
    {
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->clear()) {
                    $success = false;
                } else {
                    $this->recordLayerSuccess($priority);
                }
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'clear', 'all');
            }
        }

        return $success;
    }

    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->deleteItem($key)) {
                    $success = false;
                } else {
                    $this->recordLayerSuccess($priority);
                }
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'deleteItem', $key);
            }
        }

        return $success;
    }

    public function deleteItems(iterable $keys): bool
    {
        $keys = $this->validateAndNormalizeKeys($keys);
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->deleteItems($keys)) {
                    $success = false;
                } else {
                    $this->recordLayerSuccess($priority);
                }
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'deleteItems', implode(',', $keys));
            }
        }

        return $success;
    }

    public function save(CacheItemInterface $item): bool
    {
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->save(clone $item)) {
                    $success = false;
                } else {
                    $this->recordLayerSuccess($priority);
                }
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'save', $item->getKey());
            }
        }

        return $success;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->saveDeferred(clone $item)) {
                    $success = false;
                } else {
                    $this->recordLayerSuccess($priority);
                }
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'saveDeferred', $item->getKey());
            }
        }

        return $success;
    }

    public function commit(): bool
    {
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if (!$layer->commit()) {
                    $success = false;
                } else {
                    $this->recordLayerSuccess($priority);
                }
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'commit', 'deferred');
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $this->validateKey($key);

        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority) || !($layer instanceof AtomicOperable)) {
                continue;
            }
            try {
                $result = $layer->increment($key, $step, $initialValue);
                $this->recordLayerSuccess($priority);
                $this->propagateValueToAllLayers($key, $result, $priority);

                return $result;
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'increment', $key);
            }
        }

        return $this->nonAtomicIncrement($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->increment($key, -$step, $initialValue);
    }

    public function add(string $key, mixed $value): bool
    {
        $this->validateKey($key);
        if ($this->hasItem($key)) {
            return false;
        }

        return $this->save(new CacheItem($key, $value));
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        $this->validateKey($key);
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority) || !($layer instanceof CompareAndSwapAware)) {
                continue;
            }
            try {
                $success = $layer->compareAndSwap($key, $expected, $value);
                if ($success) {
                    $this->recordLayerSuccess($priority);
                    $this->propagateValueToAllLayers($key, $value, $priority);

                    return true;
                }

                return false;
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'compareAndSwap', $key);
            }
        }

        return false;
    }

    public function invalidateTags(iterable $tags): bool
    {
        $normalizedTags = $this->normalizeTags($tags);
        $success = true;
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if ($layer instanceof TaggablePool) {
                    if (!$layer->invalidateTags($normalizedTags)) {
                        $success = false;
                    }
                } else {
                    $layer->clear();
                }
                $this->recordLayerSuccess($priority);
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'invalidateTags', 'tags');
            }
        }

        return $success;
    }

    public function getByTags(iterable $tags): iterable
    {
        $normalizedTags = $this->normalizeTags($tags);
        $allResults = [];
        foreach (array_reverse($this->layers, true) as $priority => $layer) {
            if (!$this->isLayerAvailable($priority) || !($layer instanceof TaggablePool)) {
                continue;
            }
            try {
                $layerResults = $layer->getByTags($normalizedTags);
                if (is_iterable($layerResults)) {
                    foreach ($layerResults as $key => $value) {
                        if (!isset($allResults[$key])) {
                            $allResults[$key] = $value;
                        }
                    }
                }
                $this->recordLayerSuccess($priority);
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'getByTags', 'tags');
            }
        }

        return $allResults;
    }

    public function getTags(): iterable
    {
        $allTags = [];
        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority) || !($layer instanceof TaggablePool)) {
                continue;
            }
            try {
                $layerTags = $layer->getTags();
                if (is_iterable($layerTags)) {
                    foreach ($layerTags as $tag) {
                        $allTags[(string) $tag] = true;
                    }
                }
                $this->recordLayerSuccess($priority);
            } catch (\Throwable $exception) {
                $this->handleLayerFailure($priority, $exception, 'getTags', 'all');
            }
        }

        return array_map(fn ($tag) => new Tag($tag), array_keys($allTags));
    }

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $this->validateKey($key);
        $normalizedTags = $this->normalizeTags($tags);
        $item = new CacheItem($key, $value);
        $success = true;

        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority)) {
                continue;
            }
            try {
                if ($layer instanceof Taggable) {
                    if (!$layer->setWithTags($key, $value, $normalizedTags)) {
                        $success = false;
                    }
                } else {
                    if (!$layer->save($item)) {
                        $success = false;
                    }
                }
                $this->recordLayerSuccess($priority);
            } catch (\Throwable $exception) {
                $success = false;
                $this->handleLayerFailure($priority, $exception, 'setWithTags', $key);
            }
        }

        return $success;
    }

    /**
     * CORRIGIDO: Delega a subscrição de tópicos para as camadas que suportam esta funcionalidade.
     */
    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->validateKey($key);
        $normalizedTopics = $this->normalizeTopics($topics);

        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    $layer->subscribeTo($key, $normalizedTopics);
                    $this->recordLayerSuccess($priority);
                } catch (\Throwable $exception) {
                    $this->handleLayerFailure($priority, $exception, 'subscribeTo', $key);
                }
            }
        }
    }

    /**
     * CORRIGIDO: Delega a anulação da subscrição de tópicos para as camadas que suportam esta funcionalidade.
     */
    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        $this->validateKey($key);
        $normalizedTopics = $this->normalizeTopics($topics);

        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    $layer->unsubscribeFrom($key, $normalizedTopics);
                    $this->recordLayerSuccess($priority);
                } catch (\Throwable $exception) {
                    $this->handleLayerFailure($priority, $exception, 'unsubscribeFrom', $key);
                }
            }
        }
    }

    /**
     * CORRIGIDO: Agrega os tópicos subscritos de todas as camadas que suportam esta funcionalidade.
     */
    public function getSubscribedTopics(string $key): array
    {
        $allSubscribedTopics = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    $layerTopics = $layer->getSubscribedTopics($key);
                    $allSubscribedTopics = array_merge($allSubscribedTopics, is_array($layerTopics) ? $layerTopics : iterator_to_array($layerTopics));
                    $this->recordLayerSuccess($priority);
                } catch (\Throwable $exception) {
                    $this->handleLayerFailure($priority, $exception, 'getSubscribedTopics', $key);
                }
            }
        }

        return array_unique($allSubscribedTopics);
    }

    /**
     * CORRIGIDO: Agrega os subscritores de um tópico de todas as camadas que suportam esta funcionalidade.
     */
    public function getSubscribersForTopic(string $topic): array
    {
        $allSubscribers = [];
        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    $layerSubscribers = $layer->getSubscribersForTopic($topic);
                    $allSubscribers = array_merge($allSubscribers, is_array($layerSubscribers) ? $layerSubscribers : iterator_to_array($layerSubscribers));
                    $this->recordLayerSuccess($priority);
                } catch (\Throwable $exception) {
                    $this->handleLayerFailure($priority, $exception, 'getSubscribersForTopic', $topic);
                }
            }
        }

        return array_unique($allSubscribers);
    }

    /**
     * CORRIGIDO: Delega a invalidação de tópicos para as camadas que suportam esta funcionalidade.
     */
    public function invalidateTopics(iterable $topics): bool
    {
        $normalizedTopics = $this->normalizeTopics($topics);
        $success = true;

        foreach ($this->layers as $priority => $layer) {
            if ($this->isLayerAvailable($priority) && $layer instanceof TopicSubscribable) {
                try {
                    if (!$layer->invalidateTopics($normalizedTopics)) {
                        $success = false;
                    } else {
                        $this->recordLayerSuccess($priority);
                    }
                } catch (\Throwable $exception) {
                    $success = false;
                    $this->handleLayerFailure($priority, $exception, 'invalidateTopics', 'topics');
                }
            }
        }

        return $success;
    }

    public function maintenance(iterable $options = []): bool
    {
        $optionsArray = is_array($options) ? $options : iterator_to_array($options);
        $overallSuccess = true;

        foreach ($this->layers as $priority => $layer) {
            if (!$this->isLayerAvailable($priority) || !($layer instanceof Maintainable)) {
                continue;
            }
            try {
                if (!$layer->maintenance($optionsArray)) {
                    $overallSuccess = false;
                }
                $this->circuitBreakers[$priority]['state'] = 'closed';
                $this->circuitBreakers[$priority]['failures'] = 0;
            } catch (\Throwable $exception) {
                $overallSuccess = false;
                $this->handleLayerFailure($priority, $exception, 'maintenance', 'all');
            }
        }

        return $overallSuccess;
    }

    /**
     * Adapts a generic PSR-6 CacheItemInterface to our internal CacheItemContract implementation.
     */
    private function adaptToCacheItem(CacheItemInterface $item): CacheItemContract
    {
        if ($item instanceof CacheItemContract) {
            return $item;
        }

        return new CacheItem($item->getKey(), $item->get(), $item->isHit());
    }

    /**
     * Initializes the circuit breaker state for a new layer.
     */
    private function initializeCircuitBreaker(int $priority): void
    {
        $this->circuitBreakers[$priority] = [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed', // possible states: closed, open, half-open
        ];
    }

    /**
     * Checks if a layer is operational based on its circuit breaker state.
     */
    private function isLayerAvailable(int $priority): bool
    {
        if (!isset($this->circuitBreakers[$priority])) {
            return false;
        }

        $breaker = &$this->circuitBreakers[$priority];

        if ('open' === $breaker['state']) {
            $timeSinceFailure = time() - ($breaker['last_failure'] ?? 0);

            if ($timeSinceFailure >= self::CIRCUIT_BREAKER_TIMEOUT_SECONDS) {
                $breaker['state'] = 'half-open';

                return true;
            }

            return false;
        }

        return true;
    }

    private function recordLayerSuccess(int $priority): void
    {
        if (isset($this->circuitBreakers[$priority])) {
            $breaker = &$this->circuitBreakers[$priority];

            if ('half-open' === $breaker['state']) {
                $this->initializeCircuitBreaker($priority);
            }
        }
    }

    private function handleLayerFailure(int $priority, \Throwable $exception, string $operation, string $key): void
    {
        if (isset($this->circuitBreakers[$priority])) {
            $breaker = &$this->circuitBreakers[$priority];
            ++$breaker['failures'];
            $breaker['last_failure'] = time();

            if ($breaker['failures'] >= self::MAX_FAILURES_BEFORE_CIRCUIT_BREAK) {
                $breaker['state'] = 'open';
                $this->logger->warning('Layer circuit breaker opened', [
                    'priority' => $priority,
                    'failures' => $breaker['failures'],
                    'layer_type' => get_class($this->layers[$priority]),
                ]);
            }
        }

        $this->logger->error('Layer operation failed', [
            'priority' => $priority,
            'operation' => $operation,
            'key' => $key,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Promotes an item found in a slower layer to all faster layers.
     */
    private function promoteToHigherLayers(string $key, CacheItemInterface $item, int $sourceLayerPriority): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($priority >= $sourceLayerPriority || !$this->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer->save(clone $item);
            } catch (\Throwable $e) {
                $this->logger->debug('Item promotion failed', [
                    'key' => $key,
                    'target_priority' => $priority,
                    'source_priority' => $sourceLayerPriority,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Propagates a new value to all layers except the source.
     */
    private function propagateValueToAllLayers(string $key, mixed $value, int $sourceLayerPriority): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($priority === $sourceLayerPriority || !$this->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer->save(new CacheItem($key, $value));
            } catch (\Throwable $e) {
                $this->logger->debug('Value propagation failed', [
                    'key' => $key,
                    'target_priority' => $priority,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fallback non-atomic increment implementation.
     */
    private function nonAtomicIncrement(string $key, int $step, int $initialValue): int
    {
        $item = $this->getItem($key);
        $currentValue = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : $initialValue;
        $newValue = $currentValue + $step;

        $this->save(new CacheItem($key, $newValue));

        return $newValue;
    }
}
