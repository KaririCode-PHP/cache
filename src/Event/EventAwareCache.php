<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * Event-aware cache decorator that adds PSR-14 compatibility.
 */
final class EventAwareCache implements \KaririCode\Cache\Contract\Cache
{
    private \KaririCode\Cache\Contract\Cache $cache;
    private CacheEventBridge $eventBridge;

    public function __construct(
        \KaririCode\Cache\Contract\Cache $cache,
        CacheEventBridge $eventBridge
    ) {
        $this->cache = $cache;
        $this->eventBridge = $eventBridge;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $startTime = microtime(true);
        $result = $this->cache->get($key, $default);
        $retrievalTime = microtime(true) - $startTime;

        if ($result !== $default) {
            $this->eventBridge->dispatchCacheHit($key, $result, $retrievalTime);
        } else {
            $this->eventBridge->dispatchCacheMiss($key);
        }

        return $result;
    }

    public function set(string $key, mixed $value, $ttl = null): bool
    {
        $result = $this->cache->set($key, $value, $ttl);

        if ($result) {
            $this->eventBridge->dispatchCacheSet($key, $value, $ttl);
        }

        return $result;
    }

    public function delete(string $key): bool
    {
        $existed = $this->cache->has($key);
        $result = $this->cache->delete($key);

        if ($result) {
            $this->eventBridge->dispatchCacheDelete($key, $existed);
        }

        return $result;
    }

    public function clear(): bool
    {
        $result = $this->cache->clear();

        if ($result) {
            $this->eventBridge->dispatchCacheClear();
        }

        return $result;
    }

    public function getWithCallback(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed
    {
        $startTime = microtime(true);
        $result = $this->cache->getWithCallback($key, $callback, $ttl, $tags);
        $retrievalTime = microtime(true) - $startTime;

        if ($this->cache->has($key)) {
            $this->eventBridge->dispatchCacheHit($key, $result, $retrievalTime);
        } else {
            $this->eventBridge->dispatchCacheMiss($key, $callback);
        }

        return $result;
    }

    public function setWithTags(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        $result = $this->cache->setWithTags($key, $value, $ttl, $tags);

        if ($result) {
            $this->eventBridge->dispatchCacheSet($key, $value, $ttl, $tags);
        }

        return $result;
    }

    public function invalidateTags(array $tags): bool
    {
        $result = $this->cache->invalidateTags($tags);

        if ($result) {
            $this->eventBridge->dispatchTagInvalidation($tags);
        }

        return $result;
    }

    public function warm(array $data, $ttl = null): void
    {
        $this->eventBridge->dispatchCacheWarmup($data, $ttl);
        $this->cache->warm($data, $ttl);
    }

    // Delegate all other methods to the wrapped cache
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->cache->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, $ttl = null): bool
    {
        return $this->cache->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        return $this->cache->increment($key, $step, $initialValue, $ttl);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        return $this->cache->decrement($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        return $this->cache->add($key, $value, $ttl);
    }

    public function getMetrics(): array
    {
        return $this->cache->getMetrics();
    }

    public function advanced(): \KaririCode\Cache\Contract\CacheItemPool
    {
        return $this->cache->advanced();
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->cache->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->cache->invalidateTopics($topics);
    }

    public function getEventBridge(): CacheEventBridge
    {
        return $this->eventBridge;
    }
}
