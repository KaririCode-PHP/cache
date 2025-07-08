<?php

declare(strict_types=1);

namespace KaririCode\Cache\Item;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheKeyValidator as CacheKeyValidatorContract;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\CacheItem as CacheCacheItem;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\Cache\CacheItemInterface;

/**
 * Enhanced cache pool manager with atomic operations and tagging support.
 */
final class CachePoolManager implements CacheItemPool
{
    private CacheDriver $driver;
    private CacheKeyValidatorContract $keyValidator;
    private array $deferred = [];

    public function __construct(CacheDriver $driver, ?CacheKeyValidatorContract $keyValidator = null)
    {
        $this->driver = $driver;
        $this->keyValidator = $keyValidator ?? new CacheKeyValidator(); // Usa o validador padrão
    }

    public function getItem(string $key): CacheItem
    {
        if (!$this->keyValidator->validateKey($key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }

        // Check deferred items first
        if (isset($this->deferred[$key])) {
            return $this->deferred[$key];
        }

        $value = $this->driver->get($key);

        return new CacheItem($key, $value, null !== $value);
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
        if (!$this->keyValidator->validateKey($key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }

        return isset($this->deferred[$key]) || $this->driver->has($key);
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->driver->clear();
    }

    public function deleteItem(string $key): bool
    {
        if (!$this->keyValidator->validateKey($key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }

        unset($this->deferred[$key]);

        return $this->driver->delete($key);
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

    public function save(CacheCacheItem|CacheItemInterface $item): bool
    {
        return $this->driver->set(
            $item->getKey(),
            $item->get(),
            $item->getExpiration()
        );
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
        if (method_exists($this->driver, 'increment')) {
            return $this->driver->increment($key, $step, $initialValue, $ttl);
        }

        // Fallback implementation
        $current = $this->driver->get($key) ?? $initialValue;
        $newValue = (int) $current + $step;
        $this->driver->set($key, $newValue, $ttl);

        return $newValue;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        if (method_exists($this->driver, 'add')) {
            return $this->driver->add($key, $value, $ttl);
        }

        // Fallback implementation
        if (!$this->hasItem($key)) {
            return $this->driver->set($key, $value, $ttl);
        }

        return false;
    }

    public function invalidateTags(array $tags): bool
    {
        if (method_exists($this->driver, 'invalidateTags')) {
            return $this->driver->invalidateTags($tags);
        }

        // No tag support - return true but log warning
        trigger_error(
            'Driver ' . get_class($this->driver) . ' does not support tag invalidation',
            E_USER_WARNING
        );

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        if (method_exists($this->driver, 'subscribeTo')) {
            $this->driver->subscribeTo($key, $topics);
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        if (method_exists($this->driver, 'invalidateTopics')) {
            return $this->driver->invalidateTopics($topics);
        }

        return true;
    }
}
