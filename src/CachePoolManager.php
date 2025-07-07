<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Enhanced cache pool manager with atomic operations and tagging support.
 */
final class CachePoolManager implements CacheItemPool
{
    private CacheDriver $driver;
    private array $deferred = [];

    public function __construct(CacheDriver $driver)
    {
        $this->driver = $driver;
    }

    public function getItem(string $key): CacheItem
    {
        $this->validateKey($key);

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
        $this->validateKey($key);

        return isset($this->deferred[$key]) || $this->driver->has($key);
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
        return $this->driver->set(
            $item->getKey(),
            $item->get(),
            $item->getExpiration()
        );
    }

    public function saveDeferred(CacheItem $item): bool
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

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > 250) {
            throw InvalidArgumentException::keyTooLong($key);
        }

        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }
    }
}
