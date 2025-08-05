<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Storage;

use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Driver\Array\Operation\ArrayStorageInterface;
use KaririCode\Cache\ValueObject\MetricsSnapshot;

/**
 * In-memory storage implementation for ArrayDriver operations.
 *
 * This class provides a complete in-memory storage solution with support for
 * cache entries, expiration tracking, tag associations, and topic subscriptions.
 * It implements proper cleanup mechanisms and statistics collection.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       ArrayStorageInterface for the contract
 */
final class ArrayStorage implements ArrayStorageInterface, DriverStats
{
    /** @var array<string, mixed> Main cache storage */
    private array $storage = [];

    /** @var array<string, int> Expiration timestamps for cache entries */
    private array $expirations = [];

    /** @var array<string, array<string>> Tag to keys mapping */
    private array $tagIndex = [];

    /** @var array<string, array<string>> Key to tags mapping */
    private array $keyTags = [];

    /** @var array<string, array<string>> Topic to keys mapping */
    private array $topicIndex = [];

    /** @var array<string, array<string>> Key to topics mapping */
    private array $keyTopics = [];

    /** @var array<string, array{expiration: int|null}> Tag associations with expiration */
    private array $tagExpirations = [];

    /** @var array<string, array{expiration: int|null}> Topic subscriptions with expiration */
    private array $topicExpirations = [];

    /** @var array<string, int> Operation counters for statistics */
    private array $operationCounts = [];

    /** @var int Peak memory usage tracking */
    private int $peakMemoryUsage = 0;

    /** @var float Storage creation timestamp */
    private float $createdAt;

    public function __construct()
    {
        $this->createdAt = microtime(true);
        $this->initializeCounters();
    }

    // ========================================================================
    // Basic Storage Operations
    // ========================================================================

    public function setValue(string $key, mixed $value, ?int $expiration = null): bool
    {
        $this->incrementCounter('set');

        try {
            $this->storage[$key] = $value;

            if (null !== $expiration && $expiration > 0) {
                $this->expirations[$key] = $expiration;
            } else {
                unset($this->expirations[$key]);
            }

            $this->updateMemoryUsage();

            return true;
        } catch (\Throwable) {
            $this->incrementCounter('set_errors');

            return false;
        }
    }

    public function getValue(string $key): mixed
    {
        $this->incrementCounter('get');

        if (!$this->hasKey($key)) {
            $this->incrementCounter('misses');

            return null;
        }

        $this->incrementCounter('hits');

        return $this->storage[$key];
    }

    public function hasKey(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        // Check expiration
        if (isset($this->expirations[$key]) && $this->expirations[$key] <= time()) {
            $this->removeExpiredKey($key);

            return false;
        }

        return true;
    }

    public function deleteKey(string $key): bool
    {
        $this->incrementCounter('delete');

        if (!isset($this->storage[$key])) {
            return true; // Key doesn't exist, consider it deleted
        }

        try {
            $this->removeKeyFromAllIndexes($key);

            return true;
        } catch (\Throwable) {
            $this->incrementCounter('delete_errors');

            return false;
        }
    }

    public function getKeyExpiration(string $key): ?\DateTimeInterface
    {
        if (!isset($this->expirations[$key])) {
            return null;
        }

        return new \DateTimeImmutable('@' . $this->expirations[$key]);
    }

    // ========================================================================
    // Tag Operations
    // ========================================================================

    public function addTagAssociation(string $tag, string $key, ?int $expiration = null): void
    {
        // Add to tag index
        if (!isset($this->tagIndex[$tag])) {
            $this->tagIndex[$tag] = [];
        }

        if (!in_array($key, $this->tagIndex[$tag], true)) {
            $this->tagIndex[$tag][] = $key;
        }

        // Add to key tags
        if (!isset($this->keyTags[$key])) {
            $this->keyTags[$key] = [];
        }

        if (!in_array($tag, $this->keyTags[$key], true)) {
            $this->keyTags[$key][] = $tag;
        }

        // Store expiration if provided
        if (null !== $expiration) {
            if (!isset($this->tagExpirations[$tag])) {
                $this->tagExpirations[$tag] = [];
            }
            $this->tagExpirations[$tag][$key] = ['expiration' => $expiration];
        }
    }

    public function removeTagAssociation(string $tag, string $key): bool
    {
        try {
            // Remove from tag index
            if (isset($this->tagIndex[$tag])) {
                $index = array_search($key, $this->tagIndex[$tag], true);
                if (false !== $index) {
                    unset($this->tagIndex[$tag][$index]);
                    $this->tagIndex[$tag] = array_values($this->tagIndex[$tag]);

                    if (empty($this->tagIndex[$tag])) {
                        unset($this->tagIndex[$tag]);
                    }
                }
            }

            // Remove from key tags
            if (isset($this->keyTags[$key])) {
                $index = array_search($tag, $this->keyTags[$key], true);
                if (false !== $index) {
                    unset($this->keyTags[$key][$index]);
                    $this->keyTags[$key] = array_values($this->keyTags[$key]);

                    if (empty($this->keyTags[$key])) {
                        unset($this->keyTags[$key]);
                    }
                }
            }

            // Remove expiration
            if (isset($this->tagExpirations[$tag][$key])) {
                unset($this->tagExpirations[$tag][$key]);
                if (empty($this->tagExpirations[$tag])) {
                    unset($this->tagExpirations[$tag]);
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getTaggedKeys(string $tag): array
    {
        if (!isset($this->tagIndex[$tag])) {
            return [];
        }

        // Filter out expired keys
        $validKeys = [];
        foreach ($this->tagIndex[$tag] as $key) {
            if ($this->hasKey($key)) {
                $validKeys[] = $key;
            }
        }

        return $validKeys;
    }

    public function getKeyTags(string $key): array
    {
        return $this->keyTags[$key] ?? [];
    }

    public function getAllTags(): array
    {
        return array_keys($this->tagIndex);
    }

    public function hasTag(string $tag): bool
    {
        return isset($this->tagIndex[$tag]) && !empty($this->tagIndex[$tag]);
    }

    public function clearTagAssociations(string $tag): void
    {
        if (isset($this->tagIndex[$tag])) {
            // Remove tag from all associated keys
            foreach ($this->tagIndex[$tag] as $key) {
                if (isset($this->keyTags[$key])) {
                    $index = array_search($tag, $this->keyTags[$key], true);
                    if (false !== $index) {
                        unset($this->keyTags[$key][$index]);
                        $this->keyTags[$key] = array_values($this->keyTags[$key]);

                        if (empty($this->keyTags[$key])) {
                            unset($this->keyTags[$key]);
                        }
                    }
                }
            }

            unset($this->tagIndex[$tag]);
        }

        unset($this->tagExpirations[$tag]);
    }

    public function cleanupExpiredTagAssociations(): void
    {
        $currentTime = time();

        foreach ($this->tagExpirations as $tag => $keyExpirations) {
            foreach ($keyExpirations as $key => $data) {
                if (null !== $data['expiration'] && $data['expiration'] <= $currentTime) {
                    $this->removeTagAssociation($tag, $key);
                }
            }
        }
    }

    // ========================================================================
    // Topic Operations
    // ========================================================================

    public function addTopicSubscription(string $topic, string $key, ?int $expiration = null): void
    {
        // Add to topic index
        if (!isset($this->topicIndex[$topic])) {
            $this->topicIndex[$topic] = [];
        }

        if (!in_array($key, $this->topicIndex[$topic], true)) {
            $this->topicIndex[$topic][] = $key;
        }

        // Add to key topics
        if (!isset($this->keyTopics[$key])) {
            $this->keyTopics[$key] = [];
        }

        if (!in_array($topic, $this->keyTopics[$key], true)) {
            $this->keyTopics[$key][] = $topic;
        }

        // Store expiration if provided
        if (null !== $expiration) {
            if (!isset($this->topicExpirations[$topic])) {
                $this->topicExpirations[$topic] = [];
            }
            $this->topicExpirations[$topic][$key] = ['expiration' => $expiration];
        }
    }

    public function removeTopicSubscription(string $topic, string $key): bool
    {
        try {
            // Remove from topic index
            if (isset($this->topicIndex[$topic])) {
                $index = array_search($key, $this->topicIndex[$topic], true);
                if (false !== $index) {
                    unset($this->topicIndex[$topic][$index]);
                    $this->topicIndex[$topic] = array_values($this->topicIndex[$topic]);

                    if (empty($this->topicIndex[$topic])) {
                        unset($this->topicIndex[$topic]);
                    }
                }
            }

            // Remove from key topics
            if (isset($this->keyTopics[$key])) {
                $index = array_search($topic, $this->keyTopics[$key], true);
                if (false !== $index) {
                    unset($this->keyTopics[$key][$index]);
                    $this->keyTopics[$key] = array_values($this->keyTopics[$key]);

                    if (empty($this->keyTopics[$key])) {
                        unset($this->keyTopics[$key]);
                    }
                }
            }

            // Remove expiration
            if (isset($this->topicExpirations[$topic][$key])) {
                unset($this->topicExpirations[$topic][$key]);
                if (empty($this->topicExpirations[$topic])) {
                    unset($this->topicExpirations[$topic]);
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getTopicSubscribers(string $topic): array
    {
        if (!isset($this->topicIndex[$topic])) {
            return [];
        }

        // Filter out expired keys
        $validKeys = [];
        foreach ($this->topicIndex[$topic] as $key) {
            if ($this->hasKey($key)) {
                $validKeys[] = $key;
            }
        }

        return $validKeys;
    }

    public function getKeyTopics(string $key): array
    {
        return $this->keyTopics[$key] ?? [];
    }

    public function getAllTopics(): array
    {
        return array_keys($this->topicIndex);
    }

    public function clearTopicSubscriptions(string $topic): void
    {
        if (isset($this->topicIndex[$topic])) {
            // Remove topic from all associated keys
            foreach ($this->topicIndex[$topic] as $key) {
                if (isset($this->keyTopics[$key])) {
                    $index = array_search($topic, $this->keyTopics[$key], true);
                    if (false !== $index) {
                        unset($this->keyTopics[$key][$index]);
                        $this->keyTopics[$key] = array_values($this->keyTopics[$key]);

                        if (empty($this->keyTopics[$key])) {
                            unset($this->keyTopics[$key]);
                        }
                    }
                }
            }

            unset($this->topicIndex[$topic]);
        }

        unset($this->topicExpirations[$topic]);
    }

    public function cleanupExpiredTopicSubscriptions(): void
    {
        $currentTime = time();

        foreach ($this->topicExpirations as $topic => $keyExpirations) {
            foreach ($keyExpirations as $key => $data) {
                if (null !== $data['expiration'] && $data['expiration'] <= $currentTime) {
                    $this->removeTopicSubscription($topic, $key);
                }
            }
        }
    }

    // ========================================================================
    // Maintenance and Statistics
    // ========================================================================

    public function cleanupExpired(): void
    {
        $currentTime = time();
        $expiredKeys = [];

        foreach ($this->expirations as $key => $expiration) {
            if ($expiration <= $currentTime) {
                $expiredKeys[] = $key;
            }
        }

        foreach ($expiredKeys as $key) {
            $this->removeExpiredKey($key);
        }

        $this->incrementCounter('cleanup_runs');
        $this->operationCounts['expired_cleaned'] = count($expiredKeys);
    }

    public function garbageCollect(): void
    {
        // Clean up empty arrays and optimize memory
        $this->tagIndex = array_filter($this->tagIndex);
        $this->keyTags = array_filter($this->keyTags);
        $this->topicIndex = array_filter($this->topicIndex);
        $this->keyTopics = array_filter($this->keyTopics);
        $this->tagExpirations = array_filter($this->tagExpirations);
        $this->topicExpirations = array_filter($this->topicExpirations);

        $this->updateMemoryUsage();
        $this->incrementCounter('gc_runs');
    }

    public function getItemCount(): int
    {
        return count($this->storage);
    }

    public function getMemoryUsage(): int
    {
        return memory_get_usage();
    }

    public function getPeakMemoryUsage(): int
    {
        return max($this->peakMemoryUsage, memory_get_peak_usage());
    }

    public function getStats(): DriverStats
    {
        return $this;
    }

    public function getMetrics(): MetricsSnapshot
    {
        $uptime = microtime(true) - $this->createdAt;
        $totalOps = array_sum($this->operationCounts);

        return new MetricsSnapshot(
            hits: $this->operationCounts['hits'] ?? 0,
            misses: $this->operationCounts['misses'] ?? 0,
            totalOperations: $totalOps,
            uptimeSeconds: $uptime,
            memoryUsageBytes: $this->getMemoryUsage(),
            customMetrics: [
                'entries_count' => $this->getItemCount(),
                'tags_count' => count($this->tagIndex),
                'topics_count' => count($this->topicIndex),
                'peak_memory_bytes' => $this->getPeakMemoryUsage(),
                'operations' => $this->operationCounts,
            ]
        );
    }

    public function exportData(): array
    {
        return [
            'storage' => $this->storage,
            'expirations' => $this->expirations,
            'tagIndex' => $this->tagIndex,
            'keyTags' => $this->keyTags,
            'topicIndex' => $this->topicIndex,
            'keyTopics' => $this->keyTopics,
            'tagExpirations' => $this->tagExpirations,
            'topicExpirations' => $this->topicExpirations,
            'operationCounts' => $this->operationCounts,
            'createdAt' => $this->createdAt,
        ];
    }

    public function importData(array $data): bool
    {
        try {
            $this->storage = $data['storage'] ?? [];
            $this->expirations = $data['expirations'] ?? [];
            $this->tagIndex = $data['tagIndex'] ?? [];
            $this->keyTags = $data['keyTags'] ?? [];
            $this->topicIndex = $data['topicIndex'] ?? [];
            $this->keyTopics = $data['keyTopics'] ?? [];
            $this->tagExpirations = $data['tagExpirations'] ?? [];
            $this->topicExpirations = $data['topicExpirations'] ?? [];
            $this->operationCounts = $data['operationCounts'] ?? [];
            $this->createdAt = $data['createdAt'] ?? microtime(true);

            $this->updateMemoryUsage();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    private function removeExpiredKey(string $key): void
    {
        $this->removeKeyFromAllIndexes($key);
    }

    private function removeKeyFromAllIndexes(string $key): void
    {
        // Remove from main storage
        unset($this->storage[$key]);
        unset($this->expirations[$key]);

        // Remove from tag associations
        if (isset($this->keyTags[$key])) {
            foreach ($this->keyTags[$key] as $tag) {
                $this->removeTagAssociation($tag, $key);
            }
        }

        // Remove from topic subscriptions
        if (isset($this->keyTopics[$key])) {
            foreach ($this->keyTopics[$key] as $topic) {
                $this->removeTopicSubscription($topic, $key);
            }
        }
    }

    private function initializeCounters(): void
    {
        $operations = ['get', 'set', 'delete', 'hits', 'misses', 'set_errors', 'delete_errors', 'cleanup_runs', 'gc_runs'];
        foreach ($operations as $operation) {
            $this->operationCounts[$operation] = 0;
        }
    }

    private function incrementCounter(string $operation): void
    {
        if (!isset($this->operationCounts[$operation])) {
            $this->operationCounts[$operation] = 0;
        }
        ++$this->operationCounts[$operation];
    }

    private function updateMemoryUsage(): void
    {
        $currentMemory = memory_get_usage();
        if ($currentMemory > $this->peakMemoryUsage) {
            $this->peakMemoryUsage = $currentMemory;
        }
    }

    public function getDriverName(): string
    {
        return 'ArrayStorage';
    }

    public function getDriverVersion(): string
    {
        return '1.0.0';
    }

    public function getRawStats(): array
    {
        return [
            'entries' => $this->getItemCount(),
            'memory_usage' => $this->getMemoryUsage(),
            'peak_memory' => $this->getPeakMemoryUsage(),
            'operations' => $this->operationCounts,
        ];
    }
}
