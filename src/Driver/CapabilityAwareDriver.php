<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * A decorator that provides safe fallbacks for drivers without specific capabilities.
 * It wraps any CacheDriver and ensures that calls to advanced features (like tagging)
 * do not cause errors if the underlying driver does not support them.
 */
final class CapabilityAwareDriver implements
    CacheDriver,
    AtomicOperable,
    TaggableCacheDriver,
    TopicAwareCacheDriver
{
    use ExpirationManagementTrait;

    /** @var array<string, bool> */
    private array $capabilities = [];

    public function __construct(private CacheDriver $driver)
    {
        $this->detectCapabilities();
    }

    public function get(string $key): mixed
    {
        return $this->driver->get($key);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $expiration = null): bool
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

    public function getMultiple(iterable $keys): iterable
    {
        return $this->driver->getMultiple($keys);
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $expiration = null): bool
    {
        return $this->driver->setMultiple($values, $expiration);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->driver->deleteMultiple($keys);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        if ($this->hasCapability(CacheCapabilities::ATOMIC) && $this->driver instanceof AtomicOperable) {
            return $this->driver->increment($key, $step, $initialValue);
        }

        $current = $this->driver->get($key);
        $currentValue = is_numeric($current) ? (int) $current : $initialValue;
        $newValue = $currentValue + $step;
        $this->driver->set($key, $newValue);

        return $newValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        if ($this->hasCapability(CacheCapabilities::ATOMIC_OPERATIONS) && $this->driver instanceof AtomicOperable) {
            return $this->driver->decrement($key, $step, $initialValue);
        }

        $current = $this->driver->get($key);
        $currentValue = is_numeric($current) ? (int) $current : $initialValue;
        $newValue = $currentValue - $step;
        $this->driver->set($key, $newValue);

        return $newValue;
    }

    public function add(string $key, mixed $value): bool
    {
        if ($this->hasCapability(CacheCapabilities::ATOMIC_OPERATIONS) && $this->driver instanceof AtomicOperable) {
            return $this->driver->add($key, $value);
        }

        if ($this->driver->has($key)) {
            return false;
        }

        return $this->driver->set($key, $value);
    }

    public function getSubscribedTopics(string $key): array
    {
        if ($this->driver instanceof TopicAwareCacheDriver) {
            return $this->driver->getSubscribedTopics($key);
        }

        return [];
    }

    public function getSubscribersForTopic(string $topic): array
    {
        if ($this->driver instanceof TopicAwareCacheDriver) {
            return $this->driver->getSubscribersForTopic($topic);
        }

        return [];
    }

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        if ($this->hasCapability(CacheCapabilities::TAGGING) && $this->driver instanceof TaggableCacheDriver) {
            return $this->driver->setWithTags($key, $value, $tags);
        }

        return $this->driver->set($key, $value);
    }

    public function invalidateTags(iterable $tags): bool
    {
        if ($this->hasCapability(CacheCapabilities::TAGGING) && $this->driver instanceof TaggableCacheDriver) {
            return $this->driver->invalidateTags($tags);
        }

        return true;
    }

    public function getByTags(iterable $tags): iterable
    {
        if ($this->hasCapability(CacheCapabilities::TAGGING) && $this->driver instanceof TaggableCacheDriver) {
            return $this->driver->getByTags($tags);
        }

        return [];
    }

    public function getTags(): array
    {
        if ($this->hasCapability(CacheCapabilities::TAGGING) && $this->driver instanceof TaggableCacheDriver) {
            return $this->driver->getTags();
        }

        return [];
    }

    public function subscribeTo(string $key, iterable $topics): void
    {
        if ($this->hasCapability(CacheCapabilities::TOPICS) && $this->driver instanceof TopicAwareCacheDriver) {
            $this->driver->subscribeTo($key, $topics);
        }
    }

    public function invalidateTopics(iterable $topics): bool
    {
        if ($this->hasCapability(CacheCapabilities::TOPICS) && $this->driver instanceof TopicAwareCacheDriver) {
            return $this->driver->invalidateTopics($topics);
        }

        return true;
    }

    public function getCapabilities(): array
    {
        return $this->driver->getCapabilities();
    }

    public function getStats(): DriverStats
    {
        return $this->driver->getStats();
    }

    /**
     * Checks if the underlying driver supports a given capability.
     *
     * @param string $capability a constant from CacheCapabilities
     */
    public function hasCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    private function detectCapabilities(): void
    {
        $this->capabilities[CacheCapabilities::ATOMIC_OPERATIONS] = $this->driver instanceof AtomicOperable;
        $this->capabilities[CacheCapabilities::TAGGING] = $this->driver instanceof TaggableCacheDriver;
        $this->capabilities[CacheCapabilities::TOPICS] = $this->driver instanceof TopicAwareCacheDriver;
    }
}
