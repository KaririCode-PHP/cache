<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Handles topic-based cache operations for ArrayDriver.
 *
 * This class follows the established architectural pattern by separating
 * topic operations from the main driver class, maintaining consistency with
 * other drivers like RedisTopicOperations and MemcachedTopicOperations.
 *
 * Key features:
 * - Follows SRP by focusing solely on topic operations
 * - Uses TopicValidationTrait for consistent validation
 * - Maintains reference-based access to storage for performance
 * - Implements proper Topic object handling
 * - Provides subscription-based cache invalidation
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       TopicValidationTrait for validation logic
 * @see       Topic for topic value objects
 */
final class ArrayTopicOperations
{
    use CacheKeyValidationTrait;
    use TopicValidationTrait;

    /**
     * @param array<string, mixed> $storage Reference to main storage array
     * @param array<string, int> $expirations Reference to expiration tracking
     * @param array<string, list<string>> $topicIndex Reference to topic index
     * @param callable $removeFromIndexesCallback Callback to remove keys from all indexes
     */
    public function __construct(
        private array &$storage,
        private array &$expirations,
        private array &$topicIndex,
        private $removeFromIndexesCallback
    ) {
    }

    /**
     * Subscribes a cache key to one or more topics.
     *
     * This method allows cache keys to be associated with topics for
     * event-driven invalidation. When a topic is invalidated, all
     * subscribed keys are automatically removed from cache.
     *
     * @param string $key The cache key to subscribe
     * @param array<Topic> $topics Array of Topic objects to subscribe to
     *
     * @example
     * ```php
     * $topicOps->subscribeTo('user:123', [
     *     new Topic('user.profile.updated'),
     *     new Topic('user.permissions.changed')
     * ]);
     * ```
     */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        foreach ($topics as $topic) {
            $topicString = (string) $topic;

            if (!isset($this->topicIndex[$topicString])) {
                $this->topicIndex[$topicString] = [];
            }

            // Avoid duplicates in topic index
            if (!in_array($key, $this->topicIndex[$topicString], true)) {
                $this->topicIndex[$topicString][] = $key;
            }
        }
    }

    /**
     * Invalidates all cache keys subscribed to the given topics.
     *
     * This method implements efficient topic-based invalidation by removing
     * all cache items associated with the specified topics and cleaning up
     * the topic indices.
     *
     * @param array<Topic> $topics Array of Topic objects to invalidate
     *
     * @return bool True if topics were successfully invalidated, false otherwise
     *
     * @example
     * ```php
     * $success = $topicOps->invalidateTopics([
     *     new Topic('user.profile.updated'),
     *     new Topic('cache.refresh.all')
     * ]);
     * ```
     */
    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);

        $keysToInvalidate = [];

        // Collect all keys subscribed to the topics
        foreach ($topics as $topic) {
            $topicString = (string) $topic;
            if (isset($this->topicIndex[$topicString])) {
                $keysToInvalidate = array_merge($keysToInvalidate, $this->topicIndex[$topicString]);
                // Remove the topic index completely
                unset($this->topicIndex[$topicString]);
            }
        }

        // Remove duplicates
        $keysToInvalidate = array_unique($keysToInvalidate);

        // Delete all associated keys
        foreach ($keysToInvalidate as $key) {
            unset($this->storage[$key], $this->expirations[$key]);
            // Remove from other indices (tags, etc.)
            ($this->removeFromIndexesCallback)($key);
        }

        return true;
    }

    /**
     * Gets all cache keys subscribed to a specific topic.
     *
     * This method is useful for debugging and administrative purposes,
     * allowing inspection of topic-to-key relationships.
     *
     * @param Topic $topic The topic to search for
     *
     * @return array<string> Array of keys subscribed to the topic
     */
    public function getSubscribedKeys(Topic $topic): array
    {
        $topicString = (string) $topic;

        return $this->topicIndex[$topicString] ?? [];
    }

    /**
     * Gets all topics currently in use.
     *
     * This method returns all topic strings that currently have subscribed
     * cache items, providing visibility into the topic ecosystem.
     *
     * @return array<string> Array of unique topic strings currently in use
     */
    public function getTopics(): array
    {
        return array_keys($this->topicIndex);
    }

    /**
     * Unsubscribes a cache key from specific topics.
     *
     * This method allows fine-grained control over topic subscriptions
     * by removing a key from specific topics without affecting other
     * topic associations.
     *
     * @param string $key The cache key to unsubscribe
     * @param array<Topic> $topics Array of Topic objects to unsubscribe from
     *
     * @example
     * ```php
     * $topicOps->unsubscribeFrom('user:123', [
     *     new Topic('user.profile.updated')
     * ]);
     * ```
     */
    public function unsubscribeFrom(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        foreach ($topics as $topic) {
            $topicString = (string) $topic;

            if (isset($this->topicIndex[$topicString])) {
                $keys = &$this->topicIndex[$topicString];
                if (($index = array_search($key, $keys, true)) !== false) {
                    unset($keys[$index]);
                    // Reindex array to maintain sequential indices
                    $keys = array_values($keys);

                    // Remove empty topic indices
                    if (empty($keys)) {
                        unset($this->topicIndex[$topicString]);
                    }
                }
            }
        }
    }

    /**
     * Removes a key from the topic index when the key is deleted.
     *
     * This method is called internally when keys are removed to maintain
     * index consistency and prevent memory leaks.
     *
     * @param string $key The key to remove from topic indices
     */
    public function removeKeyFromTopicIndex(string $key): void
    {
        foreach ($this->topicIndex as $topic => &$keys) {
            if (($index = array_search($key, $keys, true)) !== false) {
                unset($keys[$index]);
                // Reindex array to maintain sequential indices
                $keys = array_values($keys);

                // Remove empty topic indices
                if (empty($keys)) {
                    unset($this->topicIndex[$topic]);
                }
            }
        }
    }

    /**
     * Gets all topics a specific key is subscribed to.
     *
     * This method is useful for understanding the topic associations
     * of a particular cache key.
     *
     * @param string $key The cache key to check
     *
     * @return array<string> Array of topic strings the key is subscribed to
     */
    public function getKeyTopics(string $key): array
    {
        $this->validateKey($key);

        $keyTopics = [];

        foreach ($this->topicIndex as $topic => $keys) {
            if (in_array($key, $keys, true)) {
                $keyTopics[] = $topic;
            }
        }

        return $keyTopics;
    }

    /**
     * Gets statistics about topic usage.
     *
     * @return array{total_topics: int, total_subscribed_keys: int, average_keys_per_topic: float}
     */
    public function getTopicStatistics(): array
    {
        $totalTopics = count($this->topicIndex);
        $totalSubscribedKeys = 0;

        foreach ($this->topicIndex as $keys) {
            $totalSubscribedKeys += count($keys);
        }

        $averageKeysPerTopic = $totalTopics > 0 ? $totalSubscribedKeys / $totalTopics : 0;

        return [
            'total_topics' => $totalTopics,
            'total_subscribed_keys' => $totalSubscribedKeys,
            'average_keys_per_topic' => round($averageKeysPerTopic, 2),
        ];
    }

    /**
     * Checks if a key is subscribed to any topics.
     *
     * This method quickly determines if a cache key has any topic
     * associations, useful for cleanup and maintenance operations.
     *
     * @param string $key The cache key to check
     *
     * @return bool True if the key is subscribed to any topics
     */
    public function hasTopicSubscriptions(string $key): bool
    {
        $this->validateKey($key);

        foreach ($this->topicIndex as $keys) {
            if (in_array($key, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts the number of keys subscribed to a specific topic.
     *
     * @param Topic $topic The topic to count subscribers for
     *
     * @return int Number of keys subscribed to the topic
     */
    public function countTopicSubscribers(Topic $topic): int
    {
        $topicString = (string) $topic;

        return count($this->topicIndex[$topicString] ?? []);
    }
}
