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
 * Manages a prioritized hierarchy of cache pools (layers).
 *
 * This class implements a layered caching strategy. When an item is fetched
 * from a lower-priority layer (e.g., a slow, persistent cache), it is
 * propagated up to higher-priority layers (e.g., a fast, in-memory cache)
 * to accelerate subsequent access.
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
        krsort($this->layers); // Sort by priority descending (higher numbers first).
    }

    public function getLayers(): array
    {
        return $this->layers;
    }

    public function getItem(string $key): CacheItemInterface
    {
        foreach ($this->layers as $priority => $layer) {
            $item = $layer->getItem($key);

            if ($item->isHit()) {
                $this->propagateToHigherLayers($key, $item, $priority);

                return $item instanceof CacheItemContract ? $item : new CacheItem($key, $item->get(), true);
            }
        }

        return new CacheItem($key);
    }

    /**
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
            if ($layer instanceof Maintainable && !$layer->maintenance($options)) {
                $success = false;
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof AtomicOperable) {
                $newValue = $layer->increment($key, $step, $initialValue, $ttl);
                $this->propagateValueToAllLayers($key, $newValue, $ttl);

                return $newValue;
            }
        }

        // Non-atomic fallback
        $item = $this->getItem($key);
        $currentValue = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : $initialValue;
        $newValue = $currentValue + $step;
        $this->propagateValueToAllLayers($key, $newValue, $ttl);

        return $newValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof AtomicOperable) {
                if ($layer->add($key, $value, $ttl)) {
                    $this->propagateValueToAllLayers($key, $value, $ttl);

                    return true;
                }

                return false; // Item already exists in an atomic layer.
            }
        }

        // Non-atomic fallback
        if ($this->hasItem($key)) {
            return false;
        }

        return $this->save((new CacheItem($key))->set($value)->expiresAfter($ttl));
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof CompareAndSwapAware) {
                if ($layer->compareAndSwap($key, $expected, $value, $ttl)) {
                    $this->propagateValueToAllLayers($key, $value, $ttl);

                    return true;
                }

                return false; // CAS failed on an atomic layer.
            }
        }
        $this->logger->warning('No layer supports atomic Compare-And-Swap. Operation cannot be guaranteed.', ['key' => $key]);

        return false;
    }

    public function invalidateTags(array $tags): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if ($layer instanceof TaggablePool && !$layer->invalidateTags($tags)) {
                $success = false;
            }
        }

        return $success;
    }

    public function getByTags(array $tags): array
    {
        // Get from the highest-priority taggable layer
        foreach ($this->layers as $layer) {
            if ($layer instanceof TaggablePool) {
                return $layer->getByTags($tags);
            }
        }

        return [];
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
            $topicStr = (string) $topic;
            if (isset($this->topicSubscriptions[$topicStr])) {
                $index = array_search($key, $this->topicSubscriptions[$topicStr], true);
                if (false !== $index) {
                    unset($this->topicSubscriptions[$topicStr][$index]);
                    if (empty($this->topicSubscriptions[$topicStr])) {
                        unset($this->topicSubscriptions[$topicStr]);
                    }
                }
            }
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        $keysToInvalidate = [];
        foreach ($topics as $topic) {
            $topicStr = (string) $topic;
            if (isset($this->topicSubscriptions[$topicStr])) {
                $keysToInvalidate = array_merge($keysToInvalidate, $this->topicSubscriptions[$topicStr]);
                unset($this->topicSubscriptions[$topicStr]);
            }
        }
        if (empty($keysToInvalidate)) {
            return true;
        }

        return $this->deleteItems(array_unique($keysToInvalidate));
    }

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

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topicSubscriptions[$topic] ?? [];
    }

    private function propagateToHigherLayers(string $key, CacheItemInterface $item, int $currentPriority): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($priority > $currentPriority) {
                try {
                    if (!$layer->hasItem($key)) {
                        $layer->save(clone $item);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to propagate cache item to a higher layer.', [
                        'key' => $key, 'priority' => $priority, 'layer' => get_class($layer), 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function propagateValueToAllLayers(string $key, mixed $value, \DateInterval|int|null $ttl): void
    {
        $item = (new CacheItem($key))->set($value);
        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }
        $this->save($item);
    }

    /** @param string[] $topics */
    private function handleTopicSubscription(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            $topicStr = (string) $topic;
            $this->topicSubscriptions[$topicStr] ??= [];
            if (!in_array($key, $this->topicSubscriptions[$topicStr], true)) {
                $this->topicSubscriptions[$topicStr][] = $key;
            }
        }
    }
}
