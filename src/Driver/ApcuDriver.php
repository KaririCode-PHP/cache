<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriverInterface;

/**
 * High-performance APCu driver with LRU eviction simulation.
 */
final class ApcuDriver implements CacheDriverInterface
{
    private string $keyPrefix;
    private array $tagIndex = [];

    public function __construct(string $keyPrefix = 'cache:')
    {
        $this->keyPrefix = $keyPrefix;

        if (!extension_loaded('apcu')) {
            throw new \RuntimeException('APCu extension is not loaded');
        }
    }

    public function get(string $key): mixed
    {
        $success = false;
        $value = apcu_fetch($this->keyPrefix . $key, $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $ttl = $expiration ? $expiration->getTimestamp() - time() : 0;

        return apcu_store($this->keyPrefix . $key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->keyPrefix . $key);
    }

    public function delete(string $key): bool
    {
        $fullKey = $this->keyPrefix . $key;
        $this->removeFromTagIndex($fullKey);

        return apcu_delete($fullKey);
    }

    public function clear(): bool
    {
        // Clear only our prefixed keys
        $info = apcu_cache_info();
        $pattern = '/^' . preg_quote($this->keyPrefix, '/') . '/';

        foreach ($info['cache_list'] as $entry) {
            if (preg_match($pattern, $entry['info'])) {
                apcu_delete($entry['info']);
            }
        }

        $this->tagIndex = [];

        return true;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $fullKey = $this->keyPrefix . $key;

        // Try atomic increment
        $result = apcu_inc($fullKey, $step);

        if (false === $result) {
            // Key doesn't exist, create it
            apcu_store($fullKey, $initialValue, $ttl ?: 0);
            $result = apcu_inc($fullKey, $step);
        }

        return $result ?: $initialValue + $step;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $fullKey = $this->keyPrefix . $key;

        if (apcu_exists($fullKey)) {
            return false;
        }

        $expiration = is_int($ttl) ? $ttl : ($ttl ? $ttl->getTimestamp() - time() : 0);

        return apcu_store($fullKey, $value, $expiration);
    }

    public function invalidateTags(array $tags): bool
    {
        $keysToDelete = [];

        foreach ($tags as $tag) {
            if (isset($this->tagIndex[$tag])) {
                $keysToDelete = array_merge($keysToDelete, $this->tagIndex[$tag]);
                unset($this->tagIndex[$tag]);
            }
        }

        if (!empty($keysToDelete)) {
            foreach (array_unique($keysToDelete) as $key) {
                apcu_delete($key);
                $this->removeFromTagIndex($key);
            }
        }

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        // Store topic subscription in shared memory
        $fullKey = $this->keyPrefix . $key;

        foreach ($topics as $topic) {
            $topicKey = 'topic:' . $topic;
            $subscribers = apcu_fetch($topicKey) ?: [];
            $subscribers[] = $fullKey;
            apcu_store($topicKey, array_unique($subscribers));
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        $keysToDelete = [];

        foreach ($topics as $topic) {
            $topicKey = 'topic:' . $topic;
            $subscribers = apcu_fetch($topicKey);

            if ($subscribers) {
                $keysToDelete = array_merge($keysToDelete, $subscribers);
                apcu_delete($topicKey);
            }
        }

        if (!empty($keysToDelete)) {
            foreach (array_unique($keysToDelete) as $key) {
                apcu_delete($key);
            }
        }

        return true;
    }

    /**
     * Add key to tag index (internal method).
     */
    public function tagItem(string $key, array $tags): void
    {
        $fullKey = $this->keyPrefix . $key;

        foreach ($tags as $tag) {
            if (!isset($this->tagIndex[$tag])) {
                $this->tagIndex[$tag] = [];
            }
            $this->tagIndex[$tag][] = $fullKey;
        }
    }

    private function removeFromTagIndex(string $fullKey): void
    {
        foreach ($this->tagIndex as $tag => $keys) {
            $index = array_search($fullKey, $keys);
            if (false !== $index) {
                unset($this->tagIndex[$tag][$index]);
                $this->tagIndex[$tag] = array_values($this->tagIndex[$tag]);

                if (empty($this->tagIndex[$tag])) {
                    unset($this->tagIndex[$tag]);
                }
            }
        }
    }
}
