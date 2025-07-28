<?php

declare(strict_types=1);

namespace KaririCode\Cache\Item;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\Behavioral\TaggablePool;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheValidator as CacheKeyValidatorContract;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Validation\CacheKeyValidator;
use Psr\Cache\CacheItemInterface;

/**
 * Acts as a capability-aware adapter between the high-level PSR-6 interface and a low-level driver.
 */
final class CachePoolManager implements
    CacheItemPool,
    Maintainable,
    TaggablePool,
    CompareAndSwapAware
{
    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function __construct(
        private readonly CacheDriver $driver,
        private readonly CacheKeyValidatorContract $keyValidator = new CacheKeyValidator()
    ) {
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->keyValidator->validateKey($key);

        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }

        $value = $this->driver->get($key);

        return new CacheItem($key, $value, null !== $value);
    }

    /**
     * @param string[] $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            $this->keyValidator->validateKey($key);
        }

        $items = [];
        $deferredItems = array_intersect_key($this->deferred, array_flip($keys));
        $keysToFetch = array_diff($keys, array_keys($deferredItems));

        if (!empty($keysToFetch)) {
            $driverItems = $this->driver->getMultiple($keysToFetch);
            foreach ($driverItems as $key => $value) {
                $items[$key] = new CacheItem($key, $value, null !== $value);
            }
        }

        return array_merge($items, $deferredItems);
    }

    public function hasItem(string $key): bool
    {
        $this->keyValidator->validateKey($key);

        return isset($this->deferred[$key]) || $this->driver->has($key);
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->driver->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->keyValidator->validateKey($key);
        unset($this->deferred[$key]);

        return $this->driver->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->keyValidator->validateKey($key);
            unset($this->deferred[$key]);
        }

        return $this->driver->deleteMultiple($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        $expiration = ($item instanceof CacheItem) ? $item->getExpiration() : null;

        return $this->driver->set(
            $item->getKey(),
            $item->get(),
            $expiration
        );
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->keyValidator->validateKey($item->getKey());
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

    public function increment(string $key, int $step = 1, int $initialValue = 0, mixed $ttl = null): int
    {
        if ($this->driver instanceof AtomicCacheDriver) {
            return $this->driver->increment($key, $step, $initialValue, $ttl);
        }

        // Non-atomic fallback for basic drivers
        $current = $this->driver->get($key);
        $currentValue = is_numeric($current) ? (int) $current : $initialValue;
        $newValue = $currentValue + $step;
        $this->driver->set($key, $newValue, $this->calculateExpiration($ttl));

        return $newValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, mixed $ttl = null): int
    {
        // Decrement is just incrementing by a negative step.
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, mixed $ttl = null): bool
    {
        if ($this->driver instanceof AtomicCacheDriver) {
            return $this->driver->add($key, $value, $ttl);
        }

        // Non-atomic fallback for basic drivers
        if ($this->hasItem($key)) {
            return false;
        }

        return $this->driver->set($key, $value, $this->calculateExpiration($ttl));
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if ($this->driver instanceof CompareAndSwapAware) {
            return $this->driver->compareAndSwap($key, $expected, $value, $ttl);
        }

        // Fail if the driver cannot guarantee atomicity.
        return false;
    }

    public function invalidateTags(array $tags): bool
    {
        if ($this->driver instanceof TaggableCacheDriver) {
            return $this->driver->invalidateTags($tags);
        }

        // If the driver doesn't support tags, the operation is a success (no-op).
        return true;
    }

    public function getByTags(array $tags): array
    {
        if ($this->driver instanceof TaggableCacheDriver) {
            return $this->driver->getByTags($tags);
        }

        return [];
    }

    public function getTags(): array
    {
        if ($this->driver instanceof TaggableCacheDriver) {
            return $this->driver->getTags();
        }

        return [];
    }

    public function maintenance(array $options = []): bool
    {
        if ($this->driver instanceof Maintainable) {
            return $this->driver->maintenance($options);
        }

        return true;
    }

    /**
     * Calculates the expiration datetime from a TTL value.
     *
     * @param mixed $ttl the TTL value (int, DateInterval, or null)
     */
    private function calculateExpiration(mixed $ttl): ?\DateTimeInterface
    {
        if (null === $ttl) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }
        if (is_int($ttl)) {
            return $ttl > 0 ? new \DateTimeImmutable('+' . $ttl . ' seconds') : null;
        }

        throw InvalidArgumentException::invalidTtl($ttl);
    }
}
