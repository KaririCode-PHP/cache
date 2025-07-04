<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Exception\CacheException;

class LayeredCacheManager implements LayeredCache
{
    /** @var array<int, CacheItemPool> */
    private array $layers = [];

    public function addLayer(CacheItemPool $cachePool, int $priority): void
    {
        $this->layers[$priority] = $cachePool;
        krsort($this->layers);
    }

    public function getItem(string $key): CacheItem
    {
        if (empty($this->layers)) {
            throw new CacheException('No cache layers configured');
        }

        foreach ($this->layers as $layer) {
            $item = $layer->getItem($key);
            if ($item->isHit()) {
                $this->propagateToHigherLayers($key, $item);

                return $item;
            }
        }

        // If item is not found in any layer, return a new CacheItem from the highest priority layer
        return $this->layers[array_key_first($this->layers)]->getItem($key);
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
            $success = $success && $layer->clear();
        }

        return $success;
    }

    public function deleteItem(string $key): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            $success = $success && $layer->deleteItem($key);
        }

        return $success;
    }

    public function deleteItems(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $success && $this->deleteItem($key);
        }

        return $success;
    }

    public function save(CacheItem $item): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            $success = $success && $layer->save($item);
        }

        return $success;
    }

    public function saveDeferred(CacheItem $item): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            $success = $success && $layer->saveDeferred($item);
        }

        return $success;
    }

    public function commit(): bool
    {
        $success = true;
        foreach ($this->layers as $layer) {
            $success = $success && $layer->commit();
        }

        return $success;
    }

    private function propagateToHigherLayers(string $key, CacheItem $item): void
    {
        $foundInLayer = false;
        foreach ($this->layers as $layer) {
            $currentItem = $layer->getItem($key);
            if ($currentItem->isHit()) {
                $foundInLayer = true;
            }
            if ($foundInLayer && !$currentItem->isHit()) {
                $layer->save($item);
            }
        }
    }
}
