<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Event\TopicInvalidationEvent;
use KaririCode\Cache\Event\TopicSubscriptionEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\ValueObject\Topic;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles cache operations related to topic subscriptions.
 * Follows SRP by focusing only on topic-related operations.
 */
final class TopicCacheOperations
{
    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Subscribes a cache item to one or more topics.
     *
     * @param string $key the cache key to subscribe
     * @param Topic[] $topics an array of Topic objects
     *
     * @throws CacheException if the subscription fails
     */
    public function subscribeTo(string $key, array $topics): void
    {
        try {
            $this->layeredCache->subscribeTo($key, $topics);

            $topicStrings = array_map(fn (Topic $t) => (string) $t, $topics);
            $this->logger->debug('Key subscribed to topics', ['key' => $key, 'topics' => $topicStrings]);

            if ($this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new TopicSubscriptionEvent($key, $topicStrings));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Topic subscription failed', [
                'key' => $key,
                'topics' => array_map(fn (Topic $t) => (string) $t, $topics),
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('subscribeTo', $key, $e);
        }
    }

    /**
     * Invalidates all cache items subscribed to the given topics.
     *
     * @param Topic[] $topics the topics to invalidate
     *
     * @throws CacheException if the invalidation fails
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTopics(array $topics): bool
    {
        try {
            $keysToInvalidate = [];
            foreach ($topics as $topic) {
                $keysToInvalidate = array_merge($keysToInvalidate, $this->getSubscribersForTopic((string) $topic));
            }
            $affectedKeys = count(array_unique($keysToInvalidate));

            $result = $this->layeredCache->invalidateTopics($topics);
            $topicStrings = array_map(fn (Topic $t) => (string) $t, $topics);

            if ($result) {
                $this->logger->info('Topics invalidated successfully', ['topics' => $topicStrings, 'affected_keys' => $affectedKeys]);
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatch(new TopicInvalidationEvent($topicStrings, $affectedKeys));
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $topicStrings = array_map(fn (Topic $t) => (string) $t, $topics);
            $this->logger->error('Topic invalidation failed', [
                'topics' => $topicStrings,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('invalidateTopics', implode(',', $topicStrings), $e);
        }
    }

    /**
     * Unsubscribes a cache item from one or more topics.
     *
     * @param string $key the cache key to unsubscribe
     * @param Topic[] $topics an array of Topic objects
     *
     * @throws CacheException if the unsubscription fails
     */
    public function unsubscribeFrom(string $key, array $topics): void
    {
        try {
            $this->layeredCache->unsubscribeFrom($key, $topics);

            $this->logger->debug('Key unsubscribed from topics', [
                'key' => $key,
                'topics' => array_map(fn (Topic $t) => (string) $t, $topics),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Topic unsubscription failed', [
                'key' => $key,
                'topics' => array_map(fn (Topic $t) => (string) $t, $topics),
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('unsubscribeFrom', $key, $e);
        }
    }

    /**
     * Gets all topics a cache item is subscribed to.
     *
     * @param string $key the unique cache key
     *
     * @return string[] an array of topic names
     */
    public function getSubscribedTopics(string $key): array
    {
        try {
            return $this->layeredCache->getSubscribedTopics($key);
        } catch (\Throwable $e) {
            $this->logger->error('Get subscribed topics failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Gets all cache keys subscribed to a specific topic.
     *
     * @param string $topic the topic name
     *
     * @return string[] an array of cache keys
     */
    public function getSubscribersForTopic(string $topic): array
    {
        try {
            return $this->layeredCache->getSubscribersForTopic($topic);
        } catch (\Throwable $e) {
            $this->logger->error('Get subscribers for topic failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
