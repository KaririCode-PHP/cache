<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\Behavioral\TaggablePool;
use KaririCode\Cache\Contract\CacheItem as CacheItemContract;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Advanced layered cache manager with intelligent propagation and backend-aware tagging.
 */
final class LayeredCacheManager implements LayeredCache
{
    /** @var array<int, CacheItemPool> */
    private array $layers = [];

    /** @var array<string, string[]> */
    private array $topicSubscriptions = [];

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addLayer(CacheItemPool $pool, int $priority): void
    {
        $this->layers[$priority] = $pool;
        krsort($this->layers); // Sort layers by priority, descending (higher priority first).
    }

    /**
     * @return CacheItemPool[]
     */
    public function getLayers(): array
    {
        return $this->layers;
    }

    public function getItem(string $key): CacheItemInterface
    {
        foreach ($this->layers as $priority => $layer) {
            $item = $layer->getItem($key);

            if ($this->isItemHit($item)) {
                $this->propagateToHigherLayers($key, $item, $priority);

                return $this->createCacheItemFromHit($key, $item);
            }
        }

        return new CacheItem($key);
    }

    private function isItemHit(CacheItemInterface $item): bool
    {
        return $item->isHit();
    }

    private function createCacheItemFromHit(string $key, CacheItemInterface $item): CacheItemInterface
    {
        if ($item instanceof CacheItemContract) {
            return $item;
        }

        return new CacheItem($key, $item->get(), true);
    }

    /**
     * @param string[] $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer->hasItem($key)) {
                return true;
            }
        }

        return false;
    }

    public function clear(): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->clear()) {
                $success = false;
                $this->logger->warning('Failed to clear layer.', ['layer' => get_class($layer)]);
            }
        }
        $this->topicSubscriptions = [];

        return $success;
    }

    public function deleteItem(string $key): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->deleteItem($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteItems(array $keys): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->deleteItems($keys)) {
                $success = false;
            }
        }

        return $success;
    }

    public function save(CacheItemInterface $item): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->save(clone $item)) {
                $success = false;
                $this->logger->warning('Failed to save item to layer.', ['key' => $item->getKey(), 'layer' => get_class($layer)]);
            }
        }

        if ($item instanceof CacheItemContract && !empty($item->getTopics())) {
            $this->handleTopicSubscription($item->getKey(), $item->getTopics());
        }

        return $success;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->saveDeferred(clone $item)) {
                $success = false;
            }
        }

        return $success;
    }

    public function commit(): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->commit()) {
                $success = false;
            }
        }

        return $success;
    }

    public function maintenance(array $options = []): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if ($layer instanceof Maintainable) {
                if (!$layer->maintenance($options)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $atomicResult = $this->tryAtomicIncrement($key, $step, $initialValue, $ttl);
        if (null !== $atomicResult) {
            return $atomicResult;
        }

        return $this->performNonAtomicIncrement($key, $step, $initialValue, $ttl);
    }

    private function tryAtomicIncrement(string $key, int $step, int $initialValue, \DateInterval|int|null $ttl): ?int
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof AtomicOperable) {
                try {
                    $newValue = $layer->increment($key, $step, $initialValue, $ttl);
                    $this->saveIncrementedValue($key, $newValue, $ttl);

                    return $newValue;
                } catch (\Throwable $e) {
                    $this->logger->warning('Atomic increment failed on layer', [
                        'layer' => get_class($layer),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    private function saveIncrementedValue(string $key, int $value, \DateInterval|int|null $ttl): void
    {
        $item = new CacheItem($key);
        $item->set($value);
        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }
        $this->save($item);
    }

    // CORREÇÃO: A assinatura agora usa o tipo correto para $ttl.
    private function performNonAtomicIncrement(string $key, int $step, int $initialValue, \DateInterval|int|null $ttl): int
    {
        $currentValue = $this->getCurrentNumericValue($key, $initialValue);
        $newValue = $currentValue + $step;

        $this->saveIncrementedValue($key, $newValue, $ttl);

        return $newValue;
    }

    private function getCurrentNumericValue(string $key, int $default): int
    {
        $item = $this->getItem($key);

        if (!$item->isHit()) {
            return $default;
        }

        $value = $item->get();

        return is_numeric($value) ? (int) $value : $default;
    }

    // CORREÇÃO: A assinatura agora usa o tipo correto para $ttl.
    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    // CORREÇÃO: A assinatura agora usa o tipo correto para $ttl.
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if ($this->hasItem($key)) {
            return false;
        }
        foreach ($this->layers as $layer) {
            if ($layer instanceof AtomicOperable) {
                if ($layer->add($key, $value, $ttl)) {
                    $this->save((new CacheItem($key))->set($value)->expiresAfter($ttl));

                    return true;
                }

                return false;
            }
        }

        return $this->save((new CacheItem($key))->set($value)->expiresAfter($ttl));
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof CompareAndSwapAware) {
                if ($layer->compareAndSwap($key, $expected, $value, $ttl)) {
                    $this->save((new CacheItem($key))->set($value)->expiresAfter($ttl));

                    return true;
                }

                return false;
            }
        }
        $this->logger->warning('No layer supports atomic Compare-And-Swap. Operation cannot be guaranteed.', ['key' => $key]);

        return false;
    }

    public function invalidateTags(array $tags): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if ($layer instanceof TaggablePool) {
                if (!$layer->invalidateTags($tags)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function getTags(): array
    {
        $allTags = [];
        foreach ($this->layers as $layer) {
            if ($layer instanceof TaggablePool) {
                $allTags = array_merge($allTags, $layer->getTags());
            }
        }

        return array_unique($allTags);
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->handleTopicSubscription($key, $topics);
    }

    public function unsubscribeFrom(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            if (isset($this->topicSubscriptions[$topic])) {
                $index = array_search($key, $this->topicSubscriptions[$topic], true);
                if (false !== $index) {
                    unset($this->topicSubscriptions[$topic][$index]);
                    if (empty($this->topicSubscriptions[$topic])) {
                        unset($this->topicSubscriptions[$topic]);
                    }
                }
            }
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        $keysToInvalidate = [];
        foreach ($topics as $topic) {
            if (isset($this->topicSubscriptions[$topic])) {
                $keysToInvalidate = array_merge($keysToInvalidate, $this->topicSubscriptions[$topic]);
                unset($this->topicSubscriptions[$topic]);
            }
        }
        if (empty($keysToInvalidate)) {
            return true;
        }

        return $this->deleteItems(array_unique($keysToInvalidate));
    }

    /** @return string[] */
    public function getSubscribedTopics(string $key): array
    {
        $subscribed = [];
        foreach ($this->topicSubscriptions as $topic => $keys) {
            if (in_array($key, $keys, true)) {
                $subscribed[] = $topic;
            }
        }

        return $subscribed;
    }

    /** @return string[] */
    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topicSubscriptions[$topic] ?? [];
    }

    private function propagateToHigherLayers(string $key, CacheItemInterface $item, int $currentPriority): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($priority > $currentPriority) { // CORREÇÃO: A lógica deve ser propagar para camadas de MAIOR prioridade (número menor, mas krsort inverte a ordem de verificação)
                try {
                    if (!$layer->hasItem($key)) {
                        $layer->save(clone $item);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to propagate cache item to a higher layer.', [
                        'key' => $key,
                        'priority' => $priority,
                        'layer' => get_class($layer),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** @param string[] $topics */
    private function handleTopicSubscription(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            $this->topicSubscriptions[$topic] ??= [];
            if (!in_array($key, $this->topicSubscriptions[$topic], true)) {
                $this->topicSubscriptions[$topic][] = $key;
            }
        }
    }
}
