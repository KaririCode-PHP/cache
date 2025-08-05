<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\Topic;
use KaririCode\Contract\DataStructure\Map;
use KaririCode\DataStructure\Set\TreeSet;

/**
 * Handles topic-based cache operations for ArrayDriver with ExpirationProvider support.
 *
 * This class manages topic subscription and invalidation operations with proper
 * TTL management using the Strategy Pattern through ExpirationProvider.
 * Uses KaririCode\DataStructure components for efficient storage management.
 *
 * Design Patterns Applied:
 * - Strategy Pattern: ExpirationProvider for TTL management
 * - Single Responsibility: Handles only topic operations
 * - Dependency Injection: All dependencies injected via constructor
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayTopicOperations
{
    use CacheKeyValidationTrait;
    use TopicValidationTrait;

    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param Map $storage Main cache storage
     * @param Map $expirations Expiration timestamps storage
     * @param Map $topicIndex Topic to keys mapping
     * @param callable $removeFromIndexesCallback Callback to remove keys from all indexes
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     */
    public function __construct(
        private readonly Map $storage,
        private readonly Map $expirations,
        private readonly Map $topicIndex,
        private readonly mixed $removeFromIndexesCallback,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * Subscribes a cache key to one or more topics with TTL support.
     *
     * @param string $key The cache key to subscribe
     * @param iterable<string|Topic> $topics Topics to subscribe to
     */
    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->validateKey($key);

        $normalizedTopics = $this->normalizeTopics($topics);
        if (empty($normalizedTopics)) {
            return; // No topics to subscribe to
        }

        $this->validateTopics($normalizedTopics);

        foreach ($normalizedTopics as $topic) {
            $this->addTopicSubscription((string) $topic, $key);
        }
    }

    /**
     * Invalidates all cache keys subscribed to the provided topics.
     *
     * @param iterable<string|Topic> $topics Topics to invalidate
     *
     * @return bool true if the operation was successful
     */
    public function invalidateTopics(iterable $topics): bool
    {
        $normalizedTopics = $this->normalizeTopics($topics);
        if (empty($normalizedTopics)) {
            return true; // No topics to invalidate
        }

        $this->validateTopics($normalizedTopics);

        $keysToDelete = new TreeSet();

        // Collect all keys from all topics
        foreach ($normalizedTopics as $topic) {
            $topicName = (string) $topic;
            if ($this->topicIndex->containsKey($topicName)) {
                $keysSet = $this->topicIndex->get($topicName);
                if ($keysSet instanceof TreeSet) {
                    foreach ($keysSet->getItems() as $key) {
                        $keysToDelete->add($key);
                    }
                }

                // Clear the topic index
                $this->topicIndex->remove($topicName);
            }
        }

        // Delete all subscribed keys using the callback
        foreach ($keysToDelete->getItems() as $key) {
            if ($this->removeFromIndexesCallback) {
                call_user_func($this->removeFromIndexesCallback, $key);
            }
        }

        return true;
    }

    /**
     * Gets all topics that a specific key is subscribed to.
     *
     * @param string $key The cache key to check
     *
     * @return array<string> List of topic names
     */
    public function getSubscribedTopics(string $key): array
    {
        $this->validateKey($key);

        $subscribedTopics = [];

        foreach ($this->topicIndex->keys() as $topicName) {
            $keysSet = $this->topicIndex->get($topicName);
            if ($keysSet instanceof TreeSet && $keysSet->contains($key)) {
                $subscribedTopics[] = $topicName;
            }
        }

        return $subscribedTopics;
    }

    /**
     * Gets all keys subscribed to a specific topic.
     *
     * @param string $topic The topic name to check
     *
     * @return array<string> List of subscribed keys
     */
    public function getSubscribersForTopic(string $topic): array
    {
        if (!$this->topicIndex->containsKey($topic)) {
            return [];
        }

        $keysSet = $this->topicIndex->get($topic);
        if (!$keysSet instanceof TreeSet) {
            return [];
        }

        // Filter out expired keys
        $validKeys = [];
        foreach ($keysSet->getItems() as $key) {
            if (!$this->isExpired($key)) {
                $validKeys[] = $key;
            }
        }

        return $validKeys;
    }

    /**
     * Gets all available topics.
     *
     * @return array<string> List of all topic names
     */
    public function getAllTopics(): array
    {
        return iterator_to_array($this->topicIndex->keys());
    }

    /**
     * Checks if a key is subscribed to any topics.
     *
     * @param string $key The cache key to check
     *
     * @return bool True if the key is subscribed to any topics
     */
    public function hasTopicSubscriptions(string $key): bool
    {
        $this->validateKey($key);

        foreach ($this->topicIndex->values() as $keysSet) {
            if ($keysSet instanceof TreeSet && $keysSet->contains($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts the number of keys subscribed to a specific topic.
     *
     * @param string|Topic $topic The topic to count subscribers for
     *
     * @return int Number of keys subscribed to the topic
     */
    public function countTopicSubscribers(string|Topic $topic): int
    {
        $topicString = $topic instanceof Topic ? (string) $topic : $topic;

        if (!$this->topicIndex->containsKey($topicString)) {
            return 0;
        }

        $keysSet = $this->topicIndex->get($topicString);
        if (!$keysSet instanceof TreeSet) {
            return 0;
        }

        return $keysSet->size();
    }

    /**
     * Unsubscribes a key from specific topics.
     *
     * @param string $key The cache key to unsubscribe
     * @param iterable<string|Topic> $topics Topics to unsubscribe from
     *
     * @return bool true if the operation was successful
     */
    public function unsubscribeFrom(string $key, iterable $topics): bool
    {
        $this->validateKey($key);

        $normalizedTopics = $this->normalizeTopics($topics);
        if (empty($normalizedTopics)) {
            return true; // No topics to unsubscribe from
        }

        $this->validateTopics($normalizedTopics);

        foreach ($normalizedTopics as $topic) {
            $this->removeTopicSubscription((string) $topic, $key);
        }

        return true;
    }

    /**
     * Adds a topic subscription for a key.
     *
     * @param string $topic The topic name
     * @param string $key The cache key
     */
    public function addTopicSubscription(string $topic, string $key): void
    {
        if (!$this->topicIndex->containsKey($topic)) {
            $this->topicIndex->put($topic, new TreeSet());
        }

        $keysSet = $this->topicIndex->get($topic);
        if ($keysSet instanceof TreeSet) {
            $keysSet->add($key);
        }
    }

    /**
     * Removes a topic subscription for a key.
     *
     * @param string $topic The topic name
     * @param string $key The cache key
     */
    public function removeTopicSubscription(string $topic, string $key): void
    {
        if ($this->topicIndex->containsKey($topic)) {
            $keysSet = $this->topicIndex->get($topic);
            if ($keysSet instanceof TreeSet) {
                $keysSet->remove($key);

                // Remove empty topic index
                if ($keysSet->isEmpty()) {
                    $this->topicIndex->remove($topic);
                }
            }
        }
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Normalizes topics to Topic objects.
     *
     * @param iterable<string|Topic> $topics
     *
     * @return array<Topic>
     */
    private function normalizeTopics(iterable $topics): array
    {
        $normalized = [];

        foreach ($topics as $topic) {
            if (is_string($topic)) {
                $normalized[] = new Topic($topic);
            } elseif ($topic instanceof Topic) {
                $normalized[] = $topic;
            }
        }

        return $normalized;
    }

    /**
     * Checks if a key is expired.
     */
    private function isExpired(string $key): bool
    {
        if (!$this->expirations->containsKey($key)) {
            return false;
        }

        $expiration = $this->expirations->get($key);

        return is_int($expiration) && $expiration < time();
    }
}
