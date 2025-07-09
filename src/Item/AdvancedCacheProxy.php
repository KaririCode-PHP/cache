<?php

declare(strict_types=1);

namespace KaririCode\Cache\Item;

use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\Serializer;
use Psr\Cache\CacheItemInterface;

/**
 * Advanced cache proxy providing full PSR-6 compatibility with enhanced features.
 */
final class AdvancedCacheProxy implements CacheItemPool
{
    private LayeredCache $layeredCache;
    private Serializer $serializer;
    private array $deferred = [];

    public function __construct(LayeredCache $layeredCache, Serializer $serializer)
    {
        $this->layeredCache = $layeredCache;
        $this->serializer = $serializer;
    }

    public function getItem(string $key): CacheItemInterface
    {
        // Check deferred items first
        if (isset($this->deferred[$key])) {
            return $this->deferred[$key];
        }

        $item = $this->layeredCache->getItem($key);

        if ($item->isHit()) {
            $value = $this->serializer->unserialize($item->get());
            $item->set($value);
        }

        return $item;
    }

    public function getItems(array $keys = []): iterable
    {
        $items = [];

        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return isset($this->deferred[$key]) || $this->layeredCache->hasItem($key);
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->layeredCache->clear();
    }

    public function deleteItem(string $key): bool
    {
        unset($this->deferred[$key]);

        return $this->layeredCache->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->deferred[$key]);
        }

        return $this->layeredCache->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        // Clone the item to avoid modifying the original
        $serializedItem = clone $item;
        $serializedItem->set($this->serializer->serialize($item->get()));

        return $this->layeredCache->save($serializedItem);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        $success = true;

        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $success = false;
            }
        }

        $this->deferred = [];

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        return $this->layeredCache->increment($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        return $this->layeredCache->add($key, $value, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->layeredCache->invalidateTags($tags);
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->layeredCache->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->layeredCache->invalidateTopics($topics);
    }

    /**
     * Create a tagged cache item with fluent API.
     */
    public function createItem(string $key): CacheItem
    {
        return new CacheItem($key);
    }

    /**
     * Batch operations for better performance.
     */
    public function getItemsWithTags(array $keys, array $tags = []): iterable
    {
        $items = $this->getItems($keys);

        if (!empty($tags)) {
            foreach ($items as $item) {
                if ($item instanceof CacheItem) {
                    $item->tag($tags);
                }
            }
        }

        return $items;
    }

    /**
     * Advanced invalidation with callback.
     */
    public function invalidateBy(callable $predicate): int
    {
        $count = 0;
        $allLayers = $this->layeredCache->getLayers();

        foreach ($allLayers as $layer) {
            // This would require implementing a scan mechanism in drivers
            // For now, we'll return 0 as this is an advanced feature
        }

        return $count;
    }
}
