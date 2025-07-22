<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationCalculatorTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Cache\ValueObject\Topic;

final class ArrayDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    TopicAwareCacheDriver
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TopicValidationTrait;
    use ExpirationCalculatorTrait;
    use SerializationTrait;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, int> */
    private array $expirations = [];

    /** @var array<string, list<string>> */
    private array $tagIndex = [];

    /** @var array<string, list<string>> */
    private array $topicIndex = [];

    /** @var array<string, float> */
    private array $accessTimes = [];

    private int $currentSize = 0;
    private int $operations = 0;

    private const MAX_MEMORY_SIZE = 50 * 1024 * 1024;
    private const MAX_ITEMS = 10000;
    private const CLEANUP_INTERVAL = 100;

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $this->incrementOperations();

        if (!isset($this->data[$key])) {
            return null;
        }
        if ($this->isExpired($key)) {
            $this->delete($key);

            return null;
        }
        $this->accessTimes[$key] = microtime(true);

        return $this->data[$key];
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();

        $valueSize = strlen($this->serialize($value));

        if (!$this->hasSpace($key, $valueSize)) {
            if (!$this->makeSpace($key, $valueSize)) {
                throw CacheException::memoryLimit(self::MAX_MEMORY_SIZE, $this->currentSize);
            }
        }

        if (isset($this->data[$key])) {
            $this->currentSize -= strlen($this->serialize($this->data[$key]));
        }

        $this->data[$key] = $value;
        $this->currentSize += $valueSize;
        $this->accessTimes[$key] = microtime(true);

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
            $this->currentSize -= strlen($this->serialize($this->data[$key]));
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
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

        $current = $this->get($key);
        if (null === $current) {
            $current = $initialValue;
        } elseif (!is_int($current)) {
            throw InvalidArgumentException::invalidValue('Cannot increment non-integer value');
        }

        $newValue = $current + $step;
        $expiration = $this->calculateExpiration($ttl);
        $this->set($key, $newValue, $expiration);

        return $newValue;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        if ($this->has($key)) {
            return false;
        }
        $expiration = $this->calculateExpiration($ttl);

        return $this->set($key, $value, $expiration);
    }

    /** @param Tag[] $tags */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        $this->incrementOperations();
        $keysToDelete = [];

        $tagStrings = array_map(fn (Tag $tag) => (string) $tag, $tags);

        foreach ($tagStrings as $tag) {
            if (isset($this->tagIndex[$tag])) {
                $keysToDelete = array_merge($keysToDelete, $this->tagIndex[$tag]);
                unset($this->tagIndex[$tag]);
            }
        }
        $this->deleteMultiple(array_unique($keysToDelete));

        return true;
    }

    /** @param Topic[] $topics */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        $topicStrings = array_map(fn (Topic $topic) => (string) $topic, $topics);

        foreach ($topicStrings as $topic) {
            $this->topicIndex[$topic][] = $key;
            $this->topicIndex[$topic] = array_unique($this->topicIndex[$topic]);
        }
    }

    /** @param Topic[] $topics */
    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);
        $this->incrementOperations();
        $keysToInvalidate = [];

        $topicStrings = array_map(fn (Topic $topic) => (string) $topic, $topics);

        foreach ($topicStrings as $topic) {
            if (isset($this->topicIndex[$topic])) {
                $keysToInvalidate = array_merge($keysToInvalidate, $this->topicIndex[$topic]);
                unset($this->topicIndex[$topic]);
            }
        }
        $this->deleteMultiple(array_unique($keysToInvalidate));

        return true;
    }

    public function getCapabilities(): array
    {
        return [CacheCapabilities::ATOMIC_OPERATIONS, CacheCapabilities::TAGGING, CacheCapabilities::TOPICS];
    }

    public function getTags(): array
    {
        return array_keys($this->tagIndex);
    }

    public function getStats(): DriverStats
    {
        $stats = [
            'total_items' => count($this->data),
            'memory_usage' => $this->currentSize,
            'memory_limit' => self::MAX_MEMORY_SIZE,
            'operations' => $this->operations,
        ];

        return new MetricsSnapshot('Array', $stats['memory_usage'], $stats['total_items'], phpversion(), $stats);
    }

    private function isExpired(string $key): bool
    {
        return isset($this->expirations[$key]) && $this->expirations[$key] < time();
    }

    private function hasSpace(string $key, int $valueSize): bool
    {
        if (!isset($this->data[$key]) && count($this->data) >= self::MAX_ITEMS) {
            return false;
        }

        $currentKeySize = isset($this->data[$key]) ? strlen($this->serialize($this->data[$key])) : 0;

        return ($this->currentSize - $currentKeySize + $valueSize) <= self::MAX_MEMORY_SIZE;
    }

    private function makeSpace(string $key, int $valueSize): bool
    {
        $this->cleanup();
        if ($this->hasSpace($key, $valueSize)) {
            return true;
        }

        return $this->evictLru($key, $valueSize);
    }

    private function evictLru(string $keyToSet, int $valueSizeToSet): bool
    {
        $currentKeySize = isset($this->data[$keyToSet]) ? strlen($this->serialize($this->data[$keyToSet])) : 0;
        $neededSpace = $valueSizeToSet - $currentKeySize;
        if ($neededSpace <= 0) {
            return true;
        }

        asort($this->accessTimes);
        $freedSpace = 0;
        foreach ($this->accessTimes as $evictKey => $accessTime) {
            if ($evictKey === $keyToSet) {
                continue;
            }
            $freedSpace += strlen($this->serialize($this->data[$evictKey]));
            $this->delete($evictKey);
            if ($freedSpace >= $neededSpace) {
                return true;
            }
        }

        return false;
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

    private function incrementOperations(): void
    {
        ++$this->operations;
        if (0 === $this->operations % self::CLEANUP_INTERVAL) {
            $this->cleanup();
        }
    }

    private function cleanup(): int
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
}
