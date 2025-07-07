<?php
s
declare(strict_types=1);


namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriverInterface;

/**
 * Optimized Redis driver with native tagging and atomic operations.
 */
final class RedisDriver implements CacheDriverInterface
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

        if ($value === false) {
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

    public function has(string $key): bool
    {
        return $this->redis->exists($this->keyPrefix . $key) > 0;
    }

    public function delete(string $key): bool
    {
        $fullKey = $this->keyPrefix . $key;

        // Remove from any tag sets
        $this->removeFromAllTags($fullKey);

        return $this->redis->del($fullKey) > 0;
    }

    public function clear(): bool
    {
        // Get all cache keys
        $pattern = $this->keyPrefix . '*';
        $keys = $this->redis->keys($pattern);

        if (empty($keys)) {
            return true;
        }

        // Delete in batches to avoid memory issues
        $batchSize = 1000;
        $batches = array_chunk($keys, $batchSize);

        foreach ($batches as $batch) {
            $this->redis->del(...$batch);
        }

        // Clear tag and topic data
        $this->redis->del(...$this->redis->keys($this->tagPrefix . '*'));
        $this->redis->del(...$this->redis->keys($this->topicPrefix . '*'));

        return true;
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
        if ($ttl && $this->redis->ttl($fullKey) === -1) {
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
            if ($this->redis->sCard($tagKey) === 0) {
                $this->redis->del($tagKey);
            }
        }
    }
}
