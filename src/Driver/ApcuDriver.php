<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriver;

/**
 * High-performance APCu driver with cross-process tagging and batch operations.
 */
final class ApcuDriver implements CacheDriver
{
    private string $keyPrefix;

    private const TAG_INDEX_PREFIX = 'tag_index::';
    private const TOPIC_INDEX_PREFIX = 'topic_index::';

    /**
     * @throws \RuntimeException if the APCu extension is not loaded or enabled
     */
    public function __construct(string $keyPrefix = 'cache::')
    {
        if (!extension_loaded('apcu')) {
            throw new \RuntimeException('APCu extension is not loaded.');
        }
        if (!apcu_enabled()) {
            throw new \RuntimeException('APCu is not enabled in php.ini.');
        }
        $this->keyPrefix = $keyPrefix;
    }

    public function get(string $key): mixed
    {
        $value = apcu_fetch($this->keyPrefix . $key, $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $ttl = $expiration ? ($expiration->getTimestamp() - time()) : 0;

        return apcu_store($this->keyPrefix . $key, $value, max(0, $ttl));
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->keyPrefix . $key);
    }

    public function delete(string $key): bool
    {
        return $this->deleteMultiple([$key]);
    }

    public function getMultiple(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $fetchedValues = apcu_fetch($prefixedKeys) ?: [];

        $results = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->keyPrefix . $key;
            $results[$key] = $fetchedValues[$prefixedKey] ?? null;
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->keyPrefix . $key] = $value;
        }

        $ttl = $expiration ? ($expiration->getTimestamp() - time()) : 0;
        $failedKeys = apcu_store($prefixedValues, null, max(0, $ttl));

        return empty($failedKeys);
    }

    public function deleteMultiple(array $keys): bool
    {
        if (empty($keys)) {
            return true;
        }
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);

        return empty(apcu_delete($prefixedKeys));
    }

    public function clear(): bool
    {
        // Use an iterator to safely clear only keys with our prefixes
        $prefixes = [
            $this->keyPrefix,
            self::TAG_INDEX_PREFIX,
            self::TOPIC_INDEX_PREFIX,
        ];
        $pattern = '/^(' . implode('|', array_map('preg_quote', $prefixes)) . ')/';
        $iterator = new \APCUIterator($pattern, \APC_ITER_KEY);

        apcu_delete($iterator);

        return true;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $fullKey = $this->keyPrefix . $key;
        $ttlValue = $ttl ?? 0;

        if (!apcu_exists($fullKey)) {
            apcu_store($fullKey, $initialValue, $ttlValue);
        }

        return apcu_inc($fullKey, $step);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $expiration = is_int($ttl) ? $ttl : ($ttl ? $ttl->getTimestamp() - time() : 0);

        return apcu_add($this->keyPrefix . $key, $value, max(0, $expiration));
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->invalidateByPrefix(self::TAG_INDEX_PREFIX, $tags);
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->updateIndex(self::TOPIC_INDEX_PREFIX, $topics, $this->keyPrefix . $key);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->invalidateByPrefix(self::TOPIC_INDEX_PREFIX, $topics);
    }

    /**
     * Associates an item key with a set of tags.
     * This method would typically be called by a higher-level cache pool.
     */
    public function tagItem(string $key, array $tags): void
    {
        $this->updateIndex(self::TAG_INDEX_PREFIX, $tags, $this->keyPrefix . $key);
    }

    private function updateIndex(string $indexPrefix, array $indexNames, string $fullKey): void
    {
        foreach ($indexNames as $name) {
            $indexKey = $indexPrefix . $name;
            // Use a lock to prevent race conditions on index updates
            if (apcu_add($indexKey . '.lock', 1, 5)) {
                $keysInIndex = apcu_fetch($indexKey) ?: [];
                if (!in_array($fullKey, $keysInIndex)) {
                    $keysInIndex[] = $fullKey;
                    apcu_store($indexKey, $keysInIndex);
                }
                apcu_delete($indexKey . '.lock');
            }
        }
    }

    private function invalidateByPrefix(string $indexPrefix, array $names): bool
    {
        if (empty($names)) {
            return true;
        }

        $indexKeys = array_map(fn ($name) => $indexPrefix . $name, $names);
        $keysFromIndexes = apcu_fetch($indexKeys);

        if (empty($keysFromIndexes)) {
            return true;
        }

        $itemKeysToDelete = [];
        foreach ($keysFromIndexes as $keys) {
            if (is_array($keys)) {
                $itemKeysToDelete = array_merge($itemKeysToDelete, $keys);
            }
        }

        if (!empty($itemKeysToDelete)) {
            apcu_delete(array_unique($itemKeysToDelete));
        }

        apcu_delete($indexKeys);

        return true;
    }
}
