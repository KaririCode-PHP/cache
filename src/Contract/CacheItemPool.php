<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheItemPool
{
    public function getItem(string $key): CacheItem;

    public function getItems(array $keys = []): iterable;

    public function hasItem(string $key): bool;

    public function clear(): bool;

    public function deleteItem(string $key): bool;

    public function deleteItems(array $keys): bool;

    public function save(CacheItem $item): bool;

    public function saveDeferred(CacheItem $item): bool;

    public function commit(): bool;
}
