<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Manages topic-based cache invalidation for the RedisDriver.
 *
 * This class is solely responsible for topic subscription and invalidation logic,
 * using Redis Sets for efficient index management. It uses the ExpirationProvider
 * to apply a long-lived TTL to the topic indexes, preventing memory leaks from
 * stale subscription data.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class RedisTopicOperations
{
    private const TOPIC_SET_PREFIX = 'topic:';

    private readonly ValidationService $validator;

    /**
     * @param \Redis $redis the Redis client instance
     * @param RedisConfig $config configuration settings
     * @param RedisErrorHandler $errorHandler error handling and retry logic
     * @param RedisConnectionManager $connectionManager connection health management
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly RedisConfig $config,
        private readonly RedisErrorHandler $errorHandler,
        private readonly RedisConnectionManager $connectionManager,
        private readonly ExpirationProvider $expirationProvider
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

        $this->errorHandler->executeWithRetry(
            fn () => $this->performSubscribeTo($key, $normalizedTopics),
            $this->connectionManager,
            'subscribeTo',
            $key
        );
    }

    /**
     * Invalidates all cache keys subscribed to the provided topics.
     *
     * @param iterable<string|Topic> $topics the topics to invalidate
     *
     * @return bool true on success
     */
    public function invalidateTopics(iterable $topics): bool
    {
        $normalizedTopics = $this->normalizeTopics($topics);
        $this->validator->validateTopics($normalizedTopics);
        if (empty($normalizedTopics)) {
            return true;
        }

        $topicNames = array_map(strval(...), $normalizedTopics);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTopics($topicNames),
            $this->connectionManager,
            'invalidateTopics',
            implode(',', $topicNames)
        );
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

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetSubscribedTopics($key),
            $this->connectionManager,
            'getSubscribedTopics',
            $key
        );

        return is_array($result) ? $result : [];
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

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetSubscribersForTopic($topic),
            $this->connectionManager,
            'getSubscribersForTopic',
            $topic
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<Topic> $topics
     */
    private function performSubscribeTo(string $key, array $topics): void
    {
        $fullKey = $this->config->keyPrefix . $key;
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        // Use a long, default TTL for topic indexes to prevent them from living forever.
        $expiration = $this->expirationProvider->calculateExpiration($this->expirationProvider->maxTtl);
        $ttl = $expiration ? max(1, $expiration->getTimestamp() - time()) : 86400 * 30; // 30 days fallback

        foreach ($topics as $topic) {
            $topicKey = $this->getTopicKey($topic);
            $pipeline->sAdd($topicKey, $fullKey);
            $pipeline->expire($topicKey, $ttl);
        }

        $results = $pipeline->exec();
        if (!is_array($results)) {
            throw CacheException::operationFailed('subscribeTo', $key, new \RuntimeException('Pipeline execution failed.'));
        }
    }

    /**
     * @param array<string> $topicStrings
     */
    private function performInvalidateTopics(array $topicStrings): bool
    {
        $topicKeys = array_map(fn (string $topic) => $this->getTopicKey(new Topic($topic)), $topicStrings);
        $keysToDelete = $this->redis->sUnion($topicKeys);

        if (!is_array($keysToDelete) || empty($keysToDelete)) {
            // Still delete the (potentially empty) topic indexes.
            if (!empty($topicKeys)) {
                $this->redis->del($topicKeys);
            }

            return true;
        }

        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        $pipeline->del($keysToDelete); // Deletes the actual cache items.
        $pipeline->del($topicKeys);    // Deletes the topic index keys.
        $results = $pipeline->exec();

        return is_array($results) && !empty($results);
    }

    private function performGetSubscribedTopics(string $key): array
    {
        $fullKey = $this->config->keyPrefix . $key;
        $subscribedTopics = [];
        $iterator = null;
        $prefix = $this->config->keyPrefix . self::TOPIC_SET_PREFIX;

        while ($topicKeys = $this->redis->scan($iterator, $prefix . '*')) {
            foreach ($topicKeys as $topicKey) {
                if ($this->redis->sIsMember($topicKey, $fullKey)) {
                    $subscribedTopics[] = substr($topicKey, strlen($prefix));
                }
            }
        }

        return $subscribedTopics;
    }

    /**
     * @return array<string>
     */
    private function performGetSubscribersForTopic(string $topic): array
    {
        $topicKey = $this->getTopicKey(new Topic($topic));
        $members = $this->redis->sMembers($topicKey);

        if (!is_array($members)) {
            return [];
        }

        $prefixLength = strlen($this->config->keyPrefix);

        return array_map(fn ($key) => substr($key, $prefixLength), $members);
    }

    private function getTopicKey(Topic $topic): string
    {
        return $this->config->keyPrefix . self::TOPIC_SET_PREFIX . (string) $topic;
    }

    /**
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
