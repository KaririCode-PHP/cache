<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Exception\InvalidArgumentException;

class CachePoolManager implements CacheItemPool
{
    private array $deferred = [];

    public function __construct(
        private readonly CacheDriver $driver
    ) {
    }

    public function getItem(string $key): CacheItem
    {
        $this->validateKey($key);
        $value = $this->driver->fetch($key);

        return new CacheStoredItem($key, $value, null !== $value);
    }

    public function getItems(array $keys = []): iterable
    {
        return array_combine($keys, array_map([$this, 'getItem'], $keys));
    }

    public function hasItem(string $key): bool
    {
        $this->validateKey($key);

        return isset($this->deferred[$key]) || $this->driver->contains($key);
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->driver->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        unset($this->deferred[$key]);

        return $this->driver->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                return false;
            }
        }

        return true;
    }

    public function save(CacheItem $item): bool
    {
        if (!$item instanceof CacheItem) {
            throw new InvalidArgumentException('Invalid CacheItem');
        }

        return $this->driver->save($item->getKey(), $item->get(), $item->getExpiration());
    }

    public function saveDeferred(CacheItem $item): bool
    {
        if (!$item instanceof CacheItem) {
            throw new InvalidArgumentException('Invalid CacheItem');
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                return false;
            }
        }
        $this->deferred = [];

        return true;
    }

    private function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $key)) {
            throw new InvalidArgumentException("Invalid key: {$key}");
        }
    }
}
