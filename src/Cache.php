<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheTransactionManager;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\InvalidArgumentException;

class Cache implements CacheItemPool
{
    private LayeredCache $layeredCache;
    private CacheTransactionManager $transactionManager;
    private Serializer $serializer;

    public function __construct(
        LayeredCache $layeredCache,
        CacheTransactionManager $transactionManager,
        Serializer $serializer
    ) {
        $this->layeredCache = $layeredCache;
        $this->transactionManager = $transactionManager;
        $this->serializer = $serializer;
    }

    public function getItem(string $key): CacheItem
    {
        $this->validateKey($key);
        $item = $this->layeredCache->getItem($key);
        if ($item->isHit()) {
            $item->set($this->serializer->unserialize($item->get()));
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
        $this->validateKey($key);

        return $this->layeredCache->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->layeredCache->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);

        return $this->layeredCache->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }

        return $this->layeredCache->deleteItems($keys);
    }

    public function save(CacheItem $item): bool
    {
        $serializedItem = clone $item;
        $serializedItem->set($this->serializer->serialize($item->get()));

        return $this->layeredCache->save($serializedItem);
    }

    public function saveDeferred(CacheItem $item): bool
    {
        $serializedItem = clone $item;
        $serializedItem->set($this->serializer->serialize($item->get()));

        return $this->layeredCache->saveDeferred($serializedItem);
    }

    public function commit(): bool
    {
        return $this->layeredCache->commit();
    }

    public function beginTransaction(): void
    {
        $this->transactionManager->begin();
    }

    public function commitTransaction(): void
    {
        $this->transactionManager->commit();
    }

    public function rollbackTransaction(): void
    {
        $this->transactionManager->rollback();
    }

    private function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $key)) {
            throw new InvalidArgumentException("Invalid key: {$key}");
        }
    }
}
