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
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Advanced layered cache manager with intelligent propagation and backend-aware tagging.
 * This class orchestrates multiple cache pools (layers) to provide a single, unified cache.
 * It prioritizes faster layers (like in-memory) over slower ones (like a database).
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

        $this->logger->info('Cache layer added.', [
            'priority' => $priority,
            'class' => get_class($pool),
        ]);
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
        if (empty($this->layers)) {
            throw CacheException::operationFailed('getItem', $key, new \RuntimeException('No cache layers are configured.'));
        }

        foreach ($this->layers as $priority => $layer) {
            $item = $layer->getItem($key);

            if ($item->isHit()) {
                // If the item is found, propagate it to higher-priority (faster) layers.
                $this->propagateToHigherLayers($key, $item, $priority);

                // Ensure the returned item respects our extended contract if it doesn't already.
                if ($item instanceof CacheItemContract) {
                    return $item;
                }

                $this->logger->debug('Adapting generic CacheItemInterface to extended contract.', ['layer' => get_class($layer)]);

                return new CacheItem($key, $item->get(), true);
            }
        }

        // If not found in any layer, return an empty miss item.
        return new CacheItem($key);
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
            // Type-safe check using the Maintainable contract.
            if ($layer instanceof Maintainable) {
                if (!$layer->maintenance($options)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, mixed $ttl = null): int
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof AtomicOperable) {
                try {
                    $newValue = $layer->increment($key, $step, $initialValue, $ttl);
                    $this->save((new CacheItem($key))->set($newValue)->expiresAfter($ttl));

                    return $newValue;
                } catch (\Throwable $e) {
                    $this->logger->warning('Atomic increment failed on layer, trying next.', ['layer' => get_class($layer), 'error' => $e->getMessage()]);
                    continue;
                }
            }
        }

        $this->logger->warning('Executing non-atomic increment as a fallback.', ['key' => $key]);
        $item = $this->getItem($key);
        $current = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : $initialValue;
        $newValue = $current + $step;
        $this->save((new CacheItem($key))->set($newValue)->expiresAfter($ttl));

        return $newValue;
    }

    public function add(string $key, mixed $value, mixed $ttl = null): bool
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
                    // Clean up empty topic arrays
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

    /**
     * @return string[]
     */
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

    /**
     * @return string[]
     */
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

    /**
     * @return string[]
     */
    public function getSubscribersForTopic(string $topic): array
    {
        return $this->topicSubscriptions[$topic] ?? [];
    }

    private function propagateToHigherLayers(string $key, CacheItemInterface $item, int $currentPriority): void
    {
        foreach ($this->layers as $priority => $layer) {
            if ($priority < $currentPriority) {
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

    /**
     * @param string[] $topics
     */
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
