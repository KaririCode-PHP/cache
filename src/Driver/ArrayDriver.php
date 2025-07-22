<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationCalculatorTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;

/**
 * An in-memory cache driver primarily for testing and development.
 * It is request-scoped and does not persist data between requests.
 */
final class ArrayDriver implements
    CacheDriver,
    AtomicCacheDriver,
    Taggable,
    TopicAwareCacheDriver
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TopicValidationTrait;
    use ExpirationCalculatorTrait;
    use SerializationTrait;

    /** @var array<string, mixed> */
    private array $storage = [];

    /** @var array<string, int> */
    private array $expirations = [];

    /** @var array<string, list<string>> */
    private array $tagIndex = [];

    /** @var array<string, list<string>> */
    private array $topicIndex = [];

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        if (!isset($this->storage[$key]) || $this->isExpired($key)) {
            $this->delete($key);

            return null;
        }

        return $this->storage[$key];
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->storage[$key] = $value;
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
        if (!isset($this->storage[$key])) {
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
        unset($this->storage[$key], $this->expirations[$key]);
        $this->removeFromIndexes($key);

        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        $this->expirations = [];
        $this->tagIndex = [];
        $this->topicIndex = [];

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
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $expiration);
        }

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $current = $this->get($key);
        if (!is_numeric($current)) {
            $current = $initialValue;
        }
        $newValue = (int) $current + $step;
        $this->set($key, $newValue, $this->calculateExpiration($ttl));

        return $newValue;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $this->calculateExpiration($ttl));
    }

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        if ($this->set($key, $value, $this->calculateExpiration($ttl))) {
            foreach ($tags as $tag) {
                $this->tagIndex[(string) $tag][] = $key;
            }

            return true;
        }

        return false;
    }

    public function invalidateTags(array $tags): bool
    {
        $keysToDelete = [];
        foreach ($tags as $tag) {
            $tagStr = (string) $tag;
            if (isset($this->tagIndex[$tagStr])) {
                $keysToDelete = array_merge($keysToDelete, $this->tagIndex[$tagStr]);
                unset($this->tagIndex[$tagStr]);
            }
        }
        $this->deleteMultiple(array_unique($keysToDelete));

        return true;
    }

    public function getByTags(array $tags): array
    {
        $keys = [];
        foreach ($tags as $tag) {
            if (isset($this->tagIndex[$tag])) {
                $keys = array_merge($keys, $this->tagIndex[$tag]);
            }
        }

        return $this->getMultiple(array_unique($keys));
    }

    public function getTags(): array
    {
        return array_keys($this->tagIndex);
    }

    public function subscribeTo(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            $this->topicIndex[(string) $topic][] = $key;
        }
    }

    public function invalidateTopics(array $topics): bool
    {
        $keysToInvalidate = [];
        foreach ($topics as $topic) {
            $topicStr = (string) $topic;
            if (isset($this->topicIndex[$topicStr])) {
                $keysToInvalidate = array_merge($keysToInvalidate, $this->topicIndex[$topicStr]);
                unset($this->topicIndex[$topicStr]);
            }
        }
        $this->deleteMultiple(array_unique($keysToInvalidate));

        return true;
    }

    public function getCapabilities(): array
    {
        return [CacheCapabilities::ATOMIC_OPERATIONS, CacheCapabilities::TAGGING, CacheCapabilities::TOPICS];
    }

    public function getStats(): DriverStats
    {
        $memoryUsage = 0;
        foreach ($this->storage as $value) {
            $memoryUsage += strlen($this->serialize($value));
        }
        $stats = ['memory_usage' => $memoryUsage, 'item_count' => count($this->storage)];

        return new MetricsSnapshot('Array', $stats['memory_usage'], $stats['item_count'], phpversion(), $stats);
    }

    private function isExpired(string $key): bool
    {
        return isset($this->expirations[$key]) && $this->expirations[$key] < time();
    }

    private function removeFromIndexes(string $key): void
    {
        foreach ($this->tagIndex as $tag => &$keys) {
            if (($index = array_search($key, $keys, true)) !== false) {
                unset($keys[$index]);
                if (empty($keys)) {
                    unset($this->tagIndex[$tag]);
                }
            }
        }
        foreach ($this->topicIndex as $topic => &$keys) {
            if (($index = array_search($key, $keys, true)) !== false) {
                unset($keys[$index]);
                if (empty($keys)) {
                    unset($this->topicIndex[$topic]);
                }
            }
        }
    }
}
