<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriver;

/**
 * Optimized Redis driver with native tagging and atomic operations.
 */
final class RedisDriver implements CacheDriver
{
    private \Redis $redis;
    private string $keyPrefix;
    private string $tagPrefix;
    private string $topicPrefix;

    public function __construct(\Redis $redis, string $keyPrefix = 'cache:', string $tagPrefix = 'tag:', string $topicPrefix = 'topic:')
    {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->tagPrefix = $tagPrefix;
        $this->topicPrefix = $topicPrefix;
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->keyPrefix . $key);

        if (false === $value) {
            return null;
        }

        return unserialize($value);
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $serialized = serialize($value);
        $fullKey = $this->keyPrefix . $key;

        if ($expiration) {
            $ttl = $expiration->getTimestamp() - time();

            return $this->redis->setex($fullKey, max(1, $ttl), $serialized);
        }

        return $this->redis->set($fullKey, $serialized);
    }

    public function getMultiple(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $rawValues = $this->redis->mget($prefixedKeys);

        $results = [];
        foreach ($keys as $index => $key) {
            // Redis mget retorna `false` para chaves não encontradas no array de resultados
            if (false !== $rawValues[$index]) {
                $results[$key] = unserialize($rawValues[$index]);
            } else {
                $results[$key] = null;
            }
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $pipe = $this->redis->multi(\Redis::PIPELINE);

        $ttl = $expiration ? ($expiration->getTimestamp() - time()) : 0;
        $ttl = max(1, $ttl);

        foreach ($values as $key => $value) {
            $fullKey = $this->keyPrefix . $key;
            $serializedValue = serialize($value);

            if ($ttl > 0) {
                $pipe->setex($fullKey, $ttl, $serializedValue);
            } else {
                $pipe->set($fullKey, $serializedValue);
            }
        }

        $results = $pipe->exec();

        return !in_array(false, $results, true);
    }

    public function deleteMultiple(array $keys): bool
    {
        if (empty($keys)) {
            return true;
        }
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);

        // Remove from all tags before deleting keys
        foreach ($prefixedKeys as $fullKey) {
            $this->removeFromAllTags($fullKey);
        }

        return $this->redis->del($prefixedKeys) > 0;
    }

    public function delete(string $key): bool
    {
        // Remove from all tags before deleting the key
        return $this->deleteMultiple([$key]);
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($this->keyPrefix . $key) > 0;
    }

    public function clear(): bool
    {
        $this->scanAndDelete($this->keyPrefix . '*');
        $this->scanAndDelete($this->tagPrefix . '*');
        $this->scanAndDelete($this->topicPrefix . '*');

        return true;
    }

    /**
     * Helper method to scan and delete keys in batches without blocking Redis.
     */
    private function scanAndDelete(string $pattern): void
    {
        $iterator = null;
        // Use SCAN to iterate over keys matching the pattern
        while ($keys = $this->redis->scan($iterator, $pattern, 500)) {
            if (!empty($keys)) {
                // Delete keys in batches
                $this->redis->del($keys);
            }
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $fullKey = $this->keyPrefix . $key;

        // Use Redis atomic increment
        if (!$this->redis->exists($fullKey)) {
            $this->redis->set($fullKey, $initialValue);
            if ($ttl) {
                $this->redis->expire($fullKey, $ttl);
            }
        }

        $result = $this->redis->incrBy($fullKey, $step);

        // Set TTL if specified and key was just created
        if ($ttl && -1 === $this->redis->ttl($fullKey)) {
            $this->redis->expire($fullKey, $ttl);
        }

        return $result;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $fullKey = $this->keyPrefix . $key;
        $serialized = serialize($value);

        // Use Redis SETNX (set if not exists)
        $result = $this->redis->set($fullKey, $serialized, ['nx']);

        if ($result && $ttl) {
            $this->redis->expire($fullKey, is_int($ttl) ? $ttl : $ttl->getTimestamp() - time());
        }

        return (bool) $result;
    }

    public function invalidateTags(array $tags): bool
    {
        if (empty($tags)) {
            return true;
        }

        $keysToDelete = [];

        foreach ($tags as $tag) {
            $tagKey = $this->tagPrefix . $tag;
            $keys = $this->redis->sMembers($tagKey);

            if (!empty($keys)) {
                $keysToDelete = array_merge($keysToDelete, $keys);
                // Remove the tag set
                $this->redis->del($tagKey);
            }
        }

        if (!empty($keysToDelete)) {
            $keysToDelete = array_unique($keysToDelete);
            $this->redis->del(...$keysToDelete);

            // Remove deleted keys from other tag sets
            foreach ($keysToDelete as $deletedKey) {
                $this->removeFromAllTags($deletedKey);
            }
        }

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $fullKey = $this->keyPrefix . $key;

        foreach ($topics as $topic) {
            $topicKey = $this->topicPrefix . $topic;
            $this->redis->sAdd($topicKey, $fullKey);
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        if (empty($topics)) {
            return true;
        }

        $keysToDelete = [];

        foreach ($topics as $topic) {
            $topicKey = $this->topicPrefix . $topic;
            $keys = $this->redis->sMembers($topicKey);

            if (!empty($keys)) {
                $keysToDelete = array_merge($keysToDelete, $keys);
                $this->redis->del($topicKey);
            }
        }

        if (!empty($keysToDelete)) {
            $keysToDelete = array_unique($keysToDelete);
            $this->redis->del(...$keysToDelete);
        }

        return true;
    }

    /**
     * Tag a cache item (internal method for CacheItem integration).
     */
    public function tagItem(string $key, array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        $fullKey = $this->keyPrefix . $key;

        foreach ($tags as $tag) {
            $tagKey = $this->tagPrefix . $tag;
            $this->redis->sAdd($tagKey, $fullKey);
        }
    }

    private function removeFromAllTags(string $fullKey): void
    {
        // Get all tag keys and remove this key from them
        $tagKeys = $this->redis->keys($this->tagPrefix . '*');

        foreach ($tagKeys as $tagKey) {
            $this->redis->sRem($tagKey, $fullKey);

            // If tag set is empty, remove it
            if (0 === $this->redis->sCard($tagKey)) {
                $this->redis->del($tagKey);
            }
        }
    }
}
