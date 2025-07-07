<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Exception\CacheException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Advanced layered cache manager with intelligent propagation and backend-aware tagging.
 */
final class LayeredCacheManager implements LayeredCache
{
    /** @var array<int, CacheItemPool> */
    private array $layers = [];

    /** @var array<string, array> */
    private array $topicSubscriptions = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addLayer(CacheItemPool $pool, int $priority): void
    {
        $this->layers[$priority] = $pool;
        krsort($this->layers); // Sort by priority descending (highest first)

        $this->logger->info('Cache layer added', [
            'priority' => $priority,
            'class' => get_class($pool),
        ]);
    }

    public function getLayers(): array
    {
        return $this->layers;
    }

    public function getItem(string $key): CacheItem
    {
        if (empty($this->layers)) {
            throw CacheException::operationFailed('getItem', $key, new \RuntimeException('No cache layers configured'));
        }

        foreach ($this->layers as $priority => $layer) {
            $item = $layer->getItem($key);

            if ($item->isHit()) {
                // Propagate to higher-priority layers (lower number = higher priority)
                $this->propagateToHigherLayers($key, $item, $priority);

                return $item;
            }
        }

        // Return empty item from highest priority layer
        return reset($this->layers)->getItem($key);
    }

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
            }
        }

        // Clear topic subscriptions
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
        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function save(CacheItem $item): bool
    {
        $success = true;

        // Save to all layers
        foreach ($this->layers as $layer) {
            if (!$layer->save($item)) {
                $success = false;
            }
        }

        // Handle topic subscriptions
        if ($item instanceof CacheItem && !empty($item->getTopics())) {
            $this->handleTopicSubscription($item->getKey(), $item->getTopics());
        }

        return $success;
    }

    public function saveDeferred(CacheItem $item): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            if (!$layer->saveDeferred($item)) {
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

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        // Try atomic increment on the first layer that supports it
        foreach ($this->layers as $layer) {
            if (method_exists($layer, 'increment')) {
                try {
                    $result = $layer->increment($key, $step, $initialValue, $ttl);

                    // Propagate result to other layers
                    $item = new CacheItem($key, $result, true);
                    if (null !== $ttl) {
                        $item->expiresAfter($ttl);
                    }

                    foreach ($this->layers as $otherLayer) {
                        if ($otherLayer !== $layer) {
                            $otherLayer->save($item);
                        }
                    }

                    return $result;
                } catch (\Exception $e) {
                    $this->logger->warning('Atomic increment failed, falling back', [
                        'key' => $key,
                        'layer' => get_class($layer),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        // Fallback to non-atomic increment
        $currentItem = $this->getItem($key);
        $current = $currentItem->isHit() ? $currentItem->get() : $initialValue;

        if (!is_int($current)) {
            $current = $initialValue;
        }

        $newValue = $current + $step;
        $item = new CacheItem($key, $newValue, true);

        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }

        $this->save($item);

        return $newValue;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        // Check if key exists in any layer
        if ($this->hasItem($key)) {
            return false;
        }

        $item = new CacheItem($key, $value, true);
        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }

        return $this->save($item);
    }

    public function invalidateTags(array $tags): bool
    {
        $success = true;

        foreach ($this->layers as $layer) {
            if (method_exists($layer, 'invalidateTags')) {
                try {
                    if (!$layer->invalidateTags($tags)) {
                        $success = false;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Tag invalidation failed on layer', [
                        'tags' => $tags,
                        'layer' => get_class($layer),
                        'error' => $e->getMessage(),
                    ]);
                    $success = false;
                }
            } else {
                // Log warning about backend not supporting tagging
                $this->logger->warning('Layer does not support tag invalidation', [
                    'layer' => get_class($layer),
                    'tags' => $tags,
                ]);
            }
        }

        return $success;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            if (!isset($this->topicSubscriptions[$topic])) {
                $this->topicSubscriptions[$topic] = [];
            }
            $this->topicSubscriptions[$topic][] = $key;
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

    private function propagateToHigherLayers(string $key, CacheItem $item, int $currentPriority): void
    {
        foreach ($this->layers as $priority => $layer) {
            // Only propagate to higher priority layers (lower numbers)
            if ($priority < $currentPriority) {
                if (!$layer->hasItem($key)) {
                    try {
                        $layer->save($item);
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to propagate cache item to higher layer', [
                            'key' => $key,
                            'priority' => $priority,
                            'layer' => get_class($layer),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    private function handleTopicSubscription(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            if (!isset($this->topicSubscriptions[$topic])) {
                $this->topicSubscriptions[$topic] = [];
            }

            if (!in_array($key, $this->topicSubscriptions[$topic])) {
                $this->topicSubscriptions[$topic][] = $key;
            }
        }
    }
}
