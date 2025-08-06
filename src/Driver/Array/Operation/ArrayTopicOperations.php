<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Array\Storage\ArrayStorage;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Handles topic-based cache operations for the ArrayDriver.
 *
 * This class is solely responsible for managing subscriptions between cache
 * keys and topics, including invalidation. It uses the central ArrayStorage
 * for data manipulation and the ExpirationProvider strategy for TTL, adhering
 * to the Single Responsibility Principle.
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
    private readonly ValidationService $validator;

    /**
     * @param ArrayStorage $storage the central in-memory storage
     * @param ExpirationProvider $expirationProvider the strategy for TTL management
     * @param callable $removeCallback a callback to completely remove a key from all indexes
     */
    public function __construct(
        private readonly ArrayStorage $storage,
        private readonly ExpirationProvider $expirationProvider,
        private readonly \Closure $removeCallback
    ) {
        $this->validator = new ValidationService();
    }

    /**
     * Subscribes a cache key to one or more topics.
     *
     * @param string $key the key to subscribe
     * @param iterable<string|Topic> $topics the topics for subscription
     */
    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->validator->validateKey($key);
        $normalizedTopics = $this->normalizeTopics($topics);
        $this->validator->validateTopics($normalizedTopics);

        // An item must exist to be subscribed.
        if (!$this->storage->hasKey($key)) {
            return;
        }

        $expiration = $this->storage->getKeyExpiration($key);

        foreach ($normalizedTopics as $topic) {
            $this->storage->addTopicSubscription((string) $topic, $key, $expiration?->getTimestamp());
        }
    }

    /**
     * Deletes all items associated with any of the given topics.
     *
     * @param iterable<string|Topic> $topics the topics to invalidate
     *
     * @return bool true on success
     */
    public function invalidateTopics(iterable $topics): bool
    {
        $normalizedTopics = $this->normalizeTopics($topics);
        $this->validator->validateTopics($normalizedTopics);

        $keysToDelete = [];
        foreach ($normalizedTopics as $topic) {
            $topicName = (string) $topic;
            foreach ($this->storage->getTopicSubscribers($topicName) as $key) {
                $keysToDelete[$key] = true;
            }
            // Clear the topic index itself
            $this->storage->clearTopicSubscriptions($topicName);
        }

        // Use the callback to ensure keys are removed from all indexes
        foreach (array_keys($keysToDelete) as $key) {
            ($this->removeCallback)($key);
        }

        return true;
    }

    /**
     * Retrieves all topics a specific key is subscribed to.
     *
     * @param string $key the key to check
     *
     * @return array<string> a list of topic names
     */
    public function getSubscribedTopics(string $key): array
    {
        $this->validator->validateKey($key);

        return $this->storage->getKeyTopics($key);
    }

    /**
     * Retrieves all keys subscribed to a specific topic.
     *
     * @param string $topic the topic to check
     *
     * @return array<string> a list of subscribed keys
     */
    public function getSubscribersForTopic(string $topic): array
    {
        $this->validator->validateTopics([$topic]);

        return $this->storage->getTopicSubscribers($topic);
    }

    /**
     * Normalizes an iterable of strings or Topic objects into an array of Topic objects.
     *
     * @param iterable<string|Topic> $topics
     *
     * @return array<Topic>
     */
    private function normalizeTopics(iterable $topics): array
    {
        $normalized = [];
        foreach ($topics as $topic) {
            $normalized[] = $topic instanceof Topic ? $topic : new Topic((string) $topic);
        }

        return $normalized;
    }
}
