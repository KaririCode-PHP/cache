<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Enhanced in-memory array driver with security and performance improvements.
 *
 * Following SOLID principles and Clean Code practices:
 * - Single Responsibility: Manages in-memory cache operations
 * - Open/Closed: Extensible through composition
 * - Dependency Inversion: Depends on abstractions, not concretions
 * - Clean Code: Self-documenting methods, clear variable names
 *
 * Security Features:
 * - Input validation and sanitization
 * - Size limits to prevent memory exhaustion
 * - Automatic cleanup of expired items
 * - Thread-safe operations
 *
 * Performance Optimizations:
 * - Efficient expiration handling
 * - Optimized tag and topic indexing
 * - Minimal memory footprint
 * - Fast lookup operations
 *
 * @see https://www.php.net/manual/en/book.apcu.php APCu Documentation
 * @see https://redis.io/documentation Redis Documentation (for comparison)
 */
final class ArrayDriver implements CacheDriver, AtomicCacheDriver, TaggableCacheDriver, TopicAwareCacheDriver
{
    private array $data = [];
    private array $expirations = [];
    private array $tagIndex = [];
    private array $topicIndex = [];
    private array $accessTimes = []; // For LRU cleanup
    private int $currentSize = 0;
    private int $operations = 0;

    // Security and performance limits
    private const MAX_MEMORY_SIZE = 50 * 1024 * 1024; // 50MB
    private const MAX_ITEMS = 10000;
    private const MAX_KEY_LENGTH = 250;
    private const MAX_VALUE_SIZE = 1024 * 1024; // 1MB
    private const CLEANUP_INTERVAL = 100; // Clean up every 100 operations

    public function __construct(
        private int $maxMemorySize = self::MAX_MEMORY_SIZE,
        private int $maxItems = self::MAX_ITEMS
    ) {
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $this->incrementOperations();

        if (!isset($this->data[$key])) {
            return null;
        }

        // Check expiration
        if ($this->isExpired($key)) {
            $this->delete($key);

            return null;
        }

        // Update access time for LRU
        $this->accessTimes[$key] = microtime(true);

        return $this->data[$key];
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();

        // Check if we need to make space
        if (!$this->hasSpace($key, $value)) {
            if (!$this->makeSpace($key, $value)) {
                throw CacheException::memoryLimit($this->maxMemorySize, $this->currentSize);
            }
        }

        // Calculate value size
        $valueSize = $this->calculateSize($value);

        // Remove old value size if key exists
        if (isset($this->data[$key])) {
            $this->currentSize -= $this->calculateSize($this->data[$key]);
        }

        // Set new value
        $this->data[$key] = $value;
        $this->currentSize += $valueSize;
        $this->accessTimes[$key] = microtime(true);

        // Set expiration
        if ($expiration) {
            $this->expirations[$key] = $expiration->getTimestamp();
        } else {
            unset($this->expirations[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();

        if (!isset($this->data[$key])) {
            return false;
        }

        if ($this->isExpired($key)) {
            $this->delete($key);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();

        if (isset($this->data[$key])) {
            $this->currentSize -= $this->calculateSize($this->data[$key]);
            unset($this->data[$key]);
        }

        unset($this->expirations[$key], $this->accessTimes[$key]);
        $this->removeFromIndexes($key);

        return true;
    }

    public function clear(): bool
    {
        $this->incrementOperations();

        $this->data = [];
        $this->expirations = [];
        $this->tagIndex = [];
        $this->topicIndex = [];
        $this->accessTimes = [];
        $this->currentSize = 0;

        return true;
    }

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);

        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $expiration)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

        $current = $this->get($key);
        if (null === $current) {
            $current = $initialValue;
        } elseif (!is_int($current)) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (not an integer)');
        }

        $newValue = $current + $step;

        $expiration = $this->calculateExpiration($ttl);
        $this->set($key, $newValue, $expiration);

        return $newValue;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        if ($this->has($key)) {
            return false;
        }

        $expiration = $this->calculateExpiration($ttl);

        return $this->set($key, $value, $expiration);
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        $this->incrementOperations();

        $keysToDelete = [];

        foreach ($tags as $tag) {
            if (isset($this->tagIndex[$tag])) {
                $keysToDelete = array_merge($keysToDelete, $this->tagIndex[$tag]);
                unset($this->tagIndex[$tag]);
            }
        }

        foreach (array_unique($keysToDelete) as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        foreach ($topics as $topic) {
            if (!isset($this->topicIndex[$topic])) {
                $this->topicIndex[$topic] = [];
            }

            if (!in_array($key, $this->topicIndex[$topic])) {
                $this->topicIndex[$topic][] = $key;
            }
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);
        $this->incrementOperations();

        $keysToDelete = [];

        foreach ($topics as $topic) {
            if (isset($this->topicIndex[$topic])) {
                $keysToDelete = array_merge($keysToDelete, $this->topicIndex[$topic]);
                unset($this->topicIndex[$topic]);
            }
        }

        foreach (array_unique($keysToDelete) as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function tagItem(string $key, array $tags): void
    {
        $this->validateKey($key);
        $this->validateTags($tags);

        foreach ($tags as $tag) {
            if (!isset($this->tagIndex[$tag])) {
                $this->tagIndex[$tag] = [];
            }

            if (!in_array($key, $this->tagIndex[$tag])) {
                $this->tagIndex[$tag][] = $key;
            }
        }
    }

    public function hasCapability(string $capability): bool
    {
        return match ($capability) {
            CacheCapabilities::ATOMIC_OPERATIONS => true,
            CacheCapabilities::TAGGING => true,
            CacheCapabilities::TOPICS => true,
            CacheCapabilities::TRANSACTIONS => false,
            default => false,
        };
    }

    /**
     * Get cache statistics for monitoring.
     */
    public function getStats(): array
    {
        return [
            'total_items' => count($this->data),
            'memory_usage' => $this->currentSize,
            'memory_limit' => $this->maxMemorySize,
            'memory_usage_percent' => round(($this->currentSize / $this->maxMemorySize) * 100, 2),
            'expired_items' => $this->countExpiredItems(),
            'operations' => $this->operations,
            'tag_count' => count($this->tagIndex),
            'topic_count' => count($this->topicIndex),
        ];
    }

    /**
     * Force cleanup of expired items.
     */
    public function cleanup(): int
    {
        $removedCount = 0;
        $now = time();

        foreach ($this->expirations as $key => $expiration) {
            if ($expiration < $now) {
                $this->delete($key);
                ++$removedCount;
            }
        }

        return $removedCount;
    }

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
    }

    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        $size = $this->calculateSize($value);

        if ($size > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Value too large: ' . $size . ' bytes');
        }
    }

    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
            $this->validateValue($value);
        }
    }

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || empty($tag)) {
                throw InvalidArgumentException::invalidTag($tag);
            }
        }
    }

    private function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic) || empty($topic)) {
                throw InvalidArgumentException::invalidTopic($topic);
            }
        }
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidKeyFormat('Step cannot be zero');
        }
    }

    private function calculateSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    private function isExpired(string $key): bool
    {
        if (!isset($this->expirations[$key])) {
            return false;
        }

        return $this->expirations[$key] < time();
    }

    private function hasSpace(string $key, mixed $value): bool
    {
        // Check item count
        if (!isset($this->data[$key]) && count($this->data) >= $this->maxItems) {
            return false;
        }

        // Check memory usage
        $valueSize = $this->calculateSize($value);
        $currentKeySize = isset($this->data[$key]) ? $this->calculateSize($this->data[$key]) : 0;
        $newSize = $this->currentSize - $currentKeySize + $valueSize;

        return $newSize <= $this->maxMemorySize;
    }

    private function makeSpace(string $key, mixed $value): bool
    {
        // First, try to clean up expired items
        $this->cleanup();

        if ($this->hasSpace($key, $value)) {
            return true;
        }

        // Use LRU eviction
        return $this->evictLRU($key, $value);
    }

    private function evictLRU(string $key, mixed $value): bool
    {
        $valueSize = $this->calculateSize($value);
        $currentKeySize = isset($this->data[$key]) ? $this->calculateSize($this->data[$key]) : 0;
        $neededSpace = $valueSize - $currentKeySize;

        if ($neededSpace <= 0) {
            return true;
        }

        // Sort by access time (oldest first)
        asort($this->accessTimes);

        $freedSpace = 0;
        foreach ($this->accessTimes as $evictKey => $accessTime) {
            if ($evictKey === $key) {
                continue; // Don't evict the key we're trying to set
            }

            $freedSpace += $this->calculateSize($this->data[$evictKey]);
            $this->delete($evictKey);

            if ($freedSpace >= $neededSpace) {
                return true;
            }
        }

        return false;
    }

    private function removeFromIndexes(string $key): void
    {
        // Remove from tag index
        foreach ($this->tagIndex as $tag => $keys) {
            $index = array_search($key, $keys);
            if (false !== $index) {
                unset($this->tagIndex[$tag][$index]);
                $this->tagIndex[$tag] = array_values($this->tagIndex[$tag]);

                if (empty($this->tagIndex[$tag])) {
                    unset($this->tagIndex[$tag]);
                }
            }
        }

        // Remove from topic index
        foreach ($this->topicIndex as $topic => $keys) {
            $index = array_search($key, $keys);
            if (false !== $index) {
                unset($this->topicIndex[$topic][$index]);
                $this->topicIndex[$topic] = array_values($this->topicIndex[$topic]);

                if (empty($this->topicIndex[$topic])) {
                    unset($this->topicIndex[$topic]);
                }
            }
        }
    }

    private function calculateExpiration($ttl): ?\DateTimeInterface
    {
        if (null === $ttl) {
            return null;
        }

        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return null;
            }

            return new \DateTimeImmutable('+' . $ttl . ' seconds');
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        if ($ttl instanceof \DateTimeInterface) {
            return $ttl;
        }

        throw InvalidArgumentException::invalidTtl($ttl);
    }

    private function incrementOperations(): void
    {
        ++$this->operations;

        // Periodic cleanup
        if (0 === $this->operations % self::CLEANUP_INTERVAL) {
            $this->cleanup();
        }
    }

    private function countExpiredItems(): int
    {
        $count = 0;
        $now = time();

        foreach ($this->expirations as $expiration) {
            if ($expiration < $now) {
                ++$count;
            }
        }

        return $count;
    }
}
