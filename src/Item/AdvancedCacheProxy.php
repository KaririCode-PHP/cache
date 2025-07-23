<?php

declare(strict_types=1);

namespace KaririCode\Cache\Item;

use KaririCode\Cache\Contract\CacheItem as CacheItemContact;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache;
use Psr\Cache\CacheItemInterface;

/**
 * PSR-6 CacheItemPool Proxy (Adapter).
 * Bridges the public-facing PSR-6 standard with the framework's internal cache logic.
 */
final class AdvancedCacheProxy implements CacheItemPool
{
    /**
     * @var array<string, CacheItemInterface>
     */
    private array $deferred = [];

    public function __construct(
        private readonly LayeredCache $layeredCache,
        private readonly CacheSerializer $serializer
    ) {
    }

    public function getItem(string $key): CacheItemInterface
    {
        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }

        $item = $this->layeredCache->getItem($key);

        return $this->processRetrievedItem($key, $item);
    }

    private function processRetrievedItem(string $key, CacheItemInterface $item): CacheItemInterface
    {
        if (!$item->isHit()) {
            return new CacheItem($key, null, false);
        }

        $rawValue = $item->get();
        if (!is_string($rawValue)) {
            return new CacheItem($key, null, false);
        }

        $value = $this->serializer->unserialize($rawValue);

        return new CacheItem($key, $value, true);
    }

    /**
     * @param string[] $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
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
        $internalItem = new CacheItem(
            $item->getKey(),
            $this->serializer->serialize($item->get())
        );

        if ($item instanceof CacheItemContact) {
            if ($expiration = $item->getExpiration()) {
                $internalItem->expiresAt($expiration);
            }
        }

        unset($this->deferred[$item->getKey()]);

        return $this->layeredCache->save($internalItem);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = clone $item;

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

    public function decrement(string $key, int $step = 1, int $initialValue = 0, mixed $ttl = null): int
    {
        return $this->layeredCache->decrement($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $serializedValue = $this->serializer->serialize($value);

        return $this->layeredCache->add($key, $serializedValue, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $serializedExpected = $this->serializer->serialize($expected);
        $serializedValue = $this->serializer->serialize($value);

        return $this->layeredCache->compareAndSwap($key, $serializedExpected, $serializedValue, $ttl);
    }
}
