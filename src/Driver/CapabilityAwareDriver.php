<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats as DriverStatsContract;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;

/**
 * A wrapper that provides safe fallbacks for drivers without specific capabilities.
 */
final class CapabilityAwareDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    TopicAwareCacheDriver
{
    private CacheDriver $driver;

    /** @var array<string, bool> */
    private array $capabilities = [];

    public function __construct(CacheDriver $driver)
    {
        $this->driver = $driver;
        $this->detectCapabilities();
    }

    public function get(string $key): mixed
    {
        return $this->driver->get($key);
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        return $this->driver->set($key, $value, $expiration);
    }

    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->driver->delete($key);
    }

    public function clear(): bool
    {
        return $this->driver->clear();
    }

    /**
     * @param string[] $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        return $this->driver->getMultiple($keys);
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        return $this->driver->setMultiple($values, $expiration);
    }

    public function deleteMultiple(array $keys): bool
    {
        return $this->driver->deleteMultiple($keys);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|\DateTimeInterface|null $ttl = null): int
    {
        if ($this->hasCapability(CacheCapabilities::ATOMIC_OPERATIONS) && $this->driver instanceof AtomicCacheDriver) {
            return $this->driver->increment($key, $step, $initialValue, $ttl);
        }

        // Non-atomic fallback
        $current = $this->driver->get($key);
        $currentValue = is_numeric($current) ? (int) $current : $initialValue;
        $newValue = $currentValue + $step;
        $this->driver->set($key, $newValue);

        return $newValue;
    }

    public function add(string $key, mixed $value, \DateInterval|int|\DateTimeInterface|null $ttl = null): bool
    {
        if ($this->hasCapability(CacheCapabilities::ATOMIC_OPERATIONS) && $this->driver instanceof AtomicCacheDriver) {
            return $this->driver->add($key, $value, $ttl);
        }

        // Non-atomic fallback
        if ($this->driver->has($key)) {
            return false;
        }

        return $this->driver->set($key, $value);
    }

    public function invalidateTags(array $tags): bool
    {
        if ($this->hasCapability(CacheCapabilities::TAGGING) && $this->driver instanceof TaggableCacheDriver) {
            return $this->driver->invalidateTags($tags);
        }

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        if ($this->hasCapability(CacheCapabilities::TOPICS) && $this->driver instanceof TopicAwareCacheDriver) {
            $this->driver->subscribeTo($key, $topics);
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        if ($this->hasCapability(CacheCapabilities::TOPICS) && $this->driver instanceof TopicAwareCacheDriver) {
            return $this->driver->invalidateTopics($topics);
        }

        return true;
    }

    /** @return list<string> */
    public function getCapabilities(): array
    {
        return $this->driver->getCapabilities();
    }

    public function getStats(): DriverStatsContract
    {
        return $this->driver->getStats();
    }

    /** @return string[] */
    public function getTags(): array
    {
        if ($this->hasCapability(CacheCapabilities::TAGGING) && $this->driver instanceof TaggableCacheDriver) {
            return $this->driver->getTags();
        }

        return [];
    }

    /**
     * Checks if the underlying driver supports a given capability.
     */
    public function hasCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    private function detectCapabilities(): void
    {
        $this->capabilities[CacheCapabilities::ATOMIC_OPERATIONS] = $this->driver instanceof AtomicCacheDriver;
        $this->capabilities[CacheCapabilities::TAGGING] = $this->driver instanceof TaggableCacheDriver;
        $this->capabilities[CacheCapabilities::TOPICS] = $this->driver instanceof TopicAwareCacheDriver;
    }
}
