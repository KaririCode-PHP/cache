<?php

declare(strict_types=1);

namespace KaririCode\Cache\Decorator;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Metrics\CacheMetrics;

/**
 * Cache decorator with rate limiting capabilities.
 *
 * This decorator limits the number of operations performed on the cache
 * within a given time window, following the Decorator Pattern.
 * It fully implements the Cache interface, adhering to the Liskov Substitution Principle.
 */
final class RateLimitedCache implements Cache
{
    /**
     * @var float[] an array of timestamps for each operation
     */
    private array $operationTimes = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly int $maxOperations = 1000,
        private readonly int $windowSize = 60 // in seconds
    ) {
    }

    // ========================================================================
    // PSR-16 / Cacheable Methods
    // ========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isAllowed()) {
            return $default;
        }
        $this->recordOperation();

        return $this->cache->get($key, $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!$this->isAllowed()) {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $default;
            }

            return $result;
        }
        $this->recordOperation();

        return $this->cache->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->has($key);
    }

    // ========================================================================
    // AtomicOperable & CompareAndSwapAware Methods
    // ========================================================================

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        if (!$this->isAllowed()) {
            return $initialValue;
        }
        $this->recordOperation();

        return $this->cache->increment($key, $step, $initialValue, $ttl);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        if (!$this->isAllowed()) {
            return $initialValue;
        }
        $this->recordOperation();

        return $this->cache->decrement($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->add($key, $value, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->compareAndSwap($key, $expected, $value, $ttl);
    }

    // ========================================================================
    // Taggable & TopicSubscribable Methods
    // ========================================================================

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->setWithTags($key, $value, $tags, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->invalidateTags($tags);
    }

    public function getByTags(array $tags): array
    {
        if (!$this->isAllowed()) {
            return [];
        }
        $this->recordOperation();

        return $this->cache->getByTags($tags);
    }

    public function getTags(): array
    {
        if (!$this->isAllowed()) {
            return [];
        }
        $this->recordOperation();

        return $this->cache->getTags();
    }

    public function subscribeTo(string $key, array $topics): void
    {
        if ($this->isAllowed()) {
            $this->recordOperation();
            $this->cache->subscribeTo($key, $topics);
        }
    }

    public function unsubscribeFrom(string $key, array $topics): void
    {
        if ($this->isAllowed()) {
            $this->recordOperation();
            $this->cache->unsubscribeFrom($key, $topics);
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }
        $this->recordOperation();

        return $this->cache->invalidateTopics($topics);
    }

    /**
     * @return string[]
     */
    public function getSubscribedTopics(string $key): array
    {
        if (!$this->isAllowed()) {
            return [];
        }
        $this->recordOperation();

        return $this->cache->getSubscribedTopics($key);
    }

    /**
     * @return string[]
     */
    public function getSubscribersForTopic(string $topic): array
    {
        if (!$this->isAllowed()) {
            return [];
        }
        $this->recordOperation();

        return $this->cache->getSubscribersForTopic($topic);
    }

    // ========================================================================
    // Core Cache Contract Methods
    // ========================================================================

    public function getWithCallback(string $key, ?callable $callback = null, \DateInterval|int|null $ttl = null, array $tags = []): mixed
    {
        if (!$this->isAllowed()) {
            return $callback ? $callback() : null;
        }
        $this->recordOperation();

        return $this->cache->getWithCallback($key, $callback, $ttl, $tags);
    }

    public function warm(array $data, \DateInterval|int|null $ttl = null): void
    {
        if ($this->isAllowed()) {
            $this->recordOperation();
            $this->cache->warm($data, $ttl);
        }
    }

    public function getName(): string
    {
        return $this->cache->getName() . ' (Rate Limited)';
    }

    public function isHealthy(): bool
    {
        return $this->cache->isHealthy();
    }

    public function maintenance(array $options = []): bool
    {
        return $this->cache->maintenance($options);
    }

    public function getMetrics(): CacheMetrics
    {
        $this->cleanupOldOperations();
        $baseMetrics = $this->cache->getMetrics()->toArray();

        $rateLimitMetrics = [
            'rate_limiting' => [
                'max_operations_per_window' => $this->maxOperations,
                'current_operations_count' => count($this->operationTimes),
                'window_size_seconds' => $this->windowSize,
            ],
        ];

        return new CacheMetrics(array_merge($baseMetrics, $rateLimitMetrics));
    }

    // ========================================================================
    // Internal Rate Limiting Logic
    // ========================================================================

    private function isAllowed(): bool
    {
        $this->cleanupOldOperations();

        return count($this->operationTimes) < $this->maxOperations;
    }

    private function recordOperation(): void
    {
        $this->operationTimes[] = microtime(true);
    }

    private function cleanupOldOperations(): void
    {
        $now = microtime(true);
        $windowStart = $now - $this->windowSize;

        $this->operationTimes = array_filter(
            $this->operationTimes,
            fn ($time) => $time > $windowStart
        );
    }
}
