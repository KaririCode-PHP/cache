<?php

declare(strict_types=1);

namespace KaririCode\Cache\Decorator;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheMetrics as CacheMetricsContract;
use KaririCode\Cache\Metrics\CacheMetrics;

/**
 * Cache decorator with rate limiting capabilities.
 */
final class RateLimitedCache implements Cache
{
    private Cache $cache;
    private int $maxOperationsPerSecond;
    private array $operationTimes = [];
    private int $windowSize;

    public function __construct(Cache $cache, int $maxOperationsPerSecond = 1000, int $windowSize = 60)
    {
        $this->cache = $cache;
        $this->maxOperationsPerSecond = $maxOperationsPerSecond;
        $this->windowSize = $windowSize;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->canPerformOperation()) {
            return $default;
        }

        $this->recordOperation();

        return $this->cache->get($key, $default);
    }

    public function set(string $key, mixed $value, $ttl = null): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!$this->canPerformOperation()) {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $default;
            }

            return $result;
        }

        $this->recordOperation();

        return $this->cache->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, $ttl = null): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->has($key);
    }

    // Implement remaining Cache interface methods with rate limiting
    public function getWithCallback(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed
    {
        if (!$this->canPerformOperation()) {
            return $callback ? $callback() : null;
        }

        $this->recordOperation();

        return $this->cache->getWithCallback($key, $callback, $ttl, $tags);
    }

    public function setWithTags(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->setWithTags($key, $value, $ttl, $tags);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        if (!$this->canPerformOperation()) {
            return $initialValue;
        }

        $this->recordOperation();

        return $this->cache->increment($key, $step, $initialValue, $ttl);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        if (!$this->canPerformOperation()) {
            return $initialValue;
        }

        $this->recordOperation();

        return $this->cache->decrement($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->add($key, $value, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->invalidateTags($tags);
    }

    public function getMetrics(): CacheMetricsContract
    {
        $metricsArray = $this->cache->getMetrics()->toArray();
        $metricsArray['rate_limiting'] = [
            'max_operations_per_second' => $this->maxOperationsPerSecond,
            'current_operations_count' => count($this->operationTimes),
            'window_size' => $this->windowSize,
        ];

        return new CacheMetrics($metricsArray);
    }

    public function advanced(): \KaririCode\Cache\Contract\CacheItemPool
    {
        return $this->cache->advanced();
    }

    public function warm(array $data, $ttl = null): void
    {
        if ($this->canPerformOperation()) {
            $this->recordOperation();
            $this->cache->warm($data, $ttl);
        }
    }

    public function subscribeTo(string $key, array $topics): void
    {
        if ($this->canPerformOperation()) {
            $this->recordOperation();
            $this->cache->subscribeTo($key, $topics);
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        if (!$this->canPerformOperation()) {
            return false;
        }

        $this->recordOperation();

        return $this->cache->invalidateTopics($topics);
    }

    private function canPerformOperation(): bool
    {
        $this->cleanupOldOperations();

        return count($this->operationTimes) < $this->maxOperationsPerSecond;
    }

    private function recordOperation(): void
    {
        $this->operationTimes[] = microtime(true);
    }

    private function cleanupOldOperations(): void
    {
        $now = microtime(true);
        $this->operationTimes = array_filter(
            $this->operationTimes,
            fn ($time) => ($now - $time) < $this->windowSize
        );
    }
}
