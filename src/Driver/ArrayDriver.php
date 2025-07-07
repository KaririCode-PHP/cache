<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriver;

/**
 * In-memory array driver for testing and request-scoped caching.
 */
final class ArrayDriver implements CacheDriver
{
    private array $data = [];
    private array $expirations = [];
    private array $tagIndex = [];
    private array $topicIndex = [];

    public function get(string $key): mixed
    {
        $this->cleanupExpired();

        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->data[$key] = $value;

        if ($expiration) {
            $this->expirations[$key] = $expiration->getTimestamp();
        } else {
            unset($this->expirations[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->cleanupExpired();

        return isset($this->data[$key]);
    }

    public function getMultiple(array $keys): array
    {
        $this->cleanupExpired();
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->data[$key] ?? null;
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $expiration);
        }

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key], $this->expirations[$key]);
        $this->removeFromIndexes($key);

        return true;
    }

    public function clear(): bool
    {
        $this->data = [];
        $this->expirations = [];
        $this->tagIndex = [];
        $this->topicIndex = [];

        return true;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $current = $this->get($key) ?? $initialValue;
        $newValue = (int) $current + $step;

        $expiration = $ttl ? new \DateTime('+' . $ttl . ' seconds') : null;
        $this->set($key, $newValue, $expiration);

        return $newValue;
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        $expiration = $ttl ? new \DateTime('+' . $ttl . ' seconds') : null;

        return $this->set($key, $value, $expiration);
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

        foreach (array_unique($keysToDelete) as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        foreach ($topics as $topic) {
            if (!isset($this->topicIndex[$topic])) {
                $this->topicIndex[$topic] = [];
            }
            $this->topicIndex[$topic][] = $key;
        }
    }

    public function invalidateTopics(array $topics): bool
    {
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

    /**
     * Tag an item (internal method).
     */
    public function tagItem(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            if (!isset($this->tagIndex[$tag])) {
                $this->tagIndex[$tag] = [];
            }
            $this->tagIndex[$tag][] = $key;
        }
    }

    /**
     * Get all data (for testing).
     */
    public function getAllData(): array
    {
        return $this->data;
    }

    private function cleanupExpired(): void
    {
        $now = time();

        foreach ($this->expirations as $key => $expiration) {
            if ($expiration < $now) {
                $this->delete($key);
            }
        }
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
}
