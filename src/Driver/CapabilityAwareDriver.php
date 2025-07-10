<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;

/**
 * Wrapper that provides safe fallbacks for drivers without specific capabilities.
 */
final class CapabilityAwareDriver implements CacheDriver, AtomicCacheDriver, TaggableCacheDriver, TopicAwareCacheDriver
{
    private CacheDriver $driver;
    private array $capabilities = [];

    public function __construct(CacheDriver $driver)
    {
        $this->driver = $driver;
        $this->detectCapabilities();
    }

    // Basic CacheDriver methods - delegate to wrapped driver
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

    public function hasCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    // AtomicCacheDriver methods
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        if ($this->driver instanceof AtomicCacheDriver) {
            return $this->driver->increment($key, $step, $initialValue, $ttl);
        }

        // Fallback implementation
        $current = $this->driver->get($key);
        if (null === $current) {
            $current = $initialValue;
        } else {
            $current = (int) $current;
        }

        $newValue = $current + $step;
        $expiration = null;

        if (null !== $ttl) {
            if (is_int($ttl)) {
                $expiration = new \DateTime('+' . $ttl . ' seconds');
            } elseif ($ttl instanceof \DateInterval) {
                $expiration = (new \DateTime())->add($ttl);
            }
        }

        $this->driver->set($key, $newValue, $expiration);

        return $newValue;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        if ($this->driver instanceof AtomicCacheDriver) {
            return $this->driver->add($key, $value, $ttl);
        }

        // Fallback implementation
        if ($this->driver->has($key)) {
            return false;
        }

        $expiration = null;
        if (null !== $ttl) {
            if (is_int($ttl)) {
                $expiration = new \DateTime('+' . $ttl . ' seconds');
            } elseif ($ttl instanceof \DateInterval) {
                $expiration = (new \DateTime())->add($ttl);
            }
        }

        return $this->driver->set($key, $value, $expiration);
    }

    // TaggableCacheDriver methods
    public function invalidateTags(array $tags): bool
    {
        if ($this->driver instanceof TaggableCacheDriver) {
            return $this->driver->invalidateTags($tags);
        }

        // No tagging support - return true but log warning if possible
        trigger_error(
            'Driver ' . get_class($this->driver) . ' does not support tag invalidation',
            E_USER_NOTICE
        );

        return true;
    }

    // TopicAwareCacheDriver methods
    public function subscribeTo(string $key, array $topics): void
    {
        if ($this->driver instanceof TopicAwareCacheDriver) {
            $this->driver->subscribeTo($key, $topics);
        }

        // No topic support - silently ignore
    }

    public function invalidateTopics(array $topics): bool
    {
        if ($this->driver instanceof TopicAwareCacheDriver) {
            return $this->driver->invalidateTopics($topics);
        }

        return true;
    }

    /**
     * Get the wrapped driver for direct access if needed.
     */
    public function getInnerDriver(): CacheDriver
    {
        return $this->driver;
    }

    private function detectCapabilities(): void
    {
        $this->capabilities[CacheCapabilities::ATOMIC_OPERATIONS] = $this->driver instanceof AtomicCacheDriver;
        $this->capabilities[CacheCapabilities::TAGGING] = $this->driver instanceof TaggableCacheDriver;
        $this->capabilities[CacheCapabilities::TOPICS] = $this->driver instanceof TopicAwareCacheDriver;

        // Check method existence as backup
        if (!$this->capabilities[CacheCapabilities::ATOMIC_OPERATIONS]) {
            $this->capabilities[CacheCapabilities::ATOMIC_OPERATIONS] =
                method_exists($this->driver, 'increment') && method_exists($this->driver, 'add');
        }
    }
}
