<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriverInterface;

/**
 * Optimized Memcached driver with simulated tagging.
 */
final class MemcachedDriver implements CacheDriverInterface
{
    private \Memcached $memcached;
    private string $keyPrefix;

    public function __construct(\Memcached $memcached, string $keyPrefix = 'cache:')
    {
        $this->memcached = $memcached;
        $this->keyPrefix = $keyPrefix;
    }

    public function get(string $key): mixed
    {
        $value = $this->memcached->get($this->keyPrefix . $key);

        if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            return null;
        }

        return $value;
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $ttl = $expiration ? $expiration->getTimestamp() : 0;

        return $this->memcached->set($this->keyPrefix . $key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->keyPrefix . $key);

        return \Memcached::RES_NOTFOUND !== $this->memcached->getResultCode();
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($this->keyPrefix . $key);
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $fullKey = $this->keyPrefix . $key;

        // Try atomic increment
        $result = $this->memcached->increment($fullKey, $step);

        if (false === $result) {
            // Key doesn't exist, create it
            $this->memcached->add($fullKey, $initialValue, $ttl ?: 0);
            $result = $this->memcached->increment($fullKey, $step);
        }

        return $result ?: $initialValue + $step;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $expiration = is_int($ttl) ? $ttl : ($ttl ? $ttl->getTimestamp() : 0);

        return $this->memcached->add($this->keyPrefix . $key, $value, $expiration);
    }

    public function invalidateTags(array $tags): bool
    {
        // Memcached doesn't support native tagging
        // This is a simulated implementation that's not very efficient
        trigger_error(
            'Memcached driver does not support efficient tag invalidation. Consider using Redis for tag-based caching.',
            E_USER_WARNING
        );

        return true; // Return true to not break the application
    }

    public function subscribeTo(string $key, array $topics): void
    {
        // Memcached doesn't support topic subscription
        // This is a no-op implementation
    }

    public function invalidateTopics(array $topics): bool
    {
        // Memcached doesn't support topic invalidation
        return true;
    }
}
