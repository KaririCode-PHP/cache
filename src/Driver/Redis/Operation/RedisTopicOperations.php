<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Manages cache operations based on topics (pub/sub for invalidation).
 * Single Responsibility: Topic subscription and invalidation.
 */
final class RedisTopicOperations
{
    use CacheKeyValidationTrait;
    use TopicValidationTrait;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager
    ) {
    }

    /**
     * Subscribes a cache key to one or more topics.
     *
     * @param string $key the key to subscribe
     * @param array<Topic> $topics the topics for subscription
     */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        if (empty($topics)) {
            throw InvalidArgumentException::invalidValue('The topics array cannot be empty.');
        }

        $topicStrings = array_map('strval', $topics);

        $this->errorHandler->executeWithRetry(
            fn () => $this->performSubscribeTo($key, $topicStrings),
            $this->connectionManager,
            'subscribeTo',
            $key
        );
    }

    /**
     * Invalidates all cache keys subscribed to the provided topics.
     *
     * @param array<Topic> $topics the topics to invalidate
     *
     * @return bool true on success
     */
    public function invalidateTopics(array $topics): bool
    {
        if (empty($topics)) {
            return true;
        }

        $this->validateTopics($topics);
        $topicStrings = array_map(fn (Topic $topic) => (string) $topic, $topics);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTopics($topicStrings),
            $this->connectionManager,
            'invalidateTopics',
            implode(',', $topicStrings)
        );
    }

    /**
     * Gets all topics a cache item is subscribed to.
     *
     * @param string $key the unique cache key
     *
     * @return array<string> a list of topic names
     */
    public function getSubscribedTopics(string $key): array
    {
        $this->validateKey($key);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetSubscribedTopics($key),
            $this->connectionManager,
            'getSubscribedTopics',
            $key
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Gets all cache items subscribed to a specific topic.
     *
     * @param string $topic the topic to search for
     *
     * @return array<string> a list of cache keys
     */
    public function getSubscribersForTopic(string $topic): array
    {
        $this->validateTopicStrings([$topic]);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetSubscribersForTopic($topic),
            $this->connectionManager,
            'getSubscribersForTopic',
            $topic
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Internal logic for the subscription operation.
     *
     * @param string $key the key to subscribe
     * @param array<string> $topicStrings the topic names
     */
    private function performSubscribeTo(string $key, array $topicStrings): void
    {
        try {
            $fullKey = $this->config->keyPrefix . $key;
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            foreach ($topicStrings as $topic) {
                $pipe->sAdd($this->config->topicPrefix . $topic, $fullKey);
            }

            $results = $pipe->exec();

            if (!is_array($results)) {
                throw CacheException::operationFailed('subscribeTo', $key, new \RuntimeException('Pipeline execution failed.'));
            }
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('subscribeTo', $key, $e);
        }
    }

    /**
     * Internal logic for topic invalidation operation.
     *
     * @param array<string> $topicStrings the topic names
     *
     * @return bool true if the operation was successful
     */
    private function performInvalidateTopics(array $topicStrings): bool
    {
        try {
            $topicKeys = array_map(fn (string $topic) => $this->config->topicPrefix . $topic, $topicStrings);

            $keysToDelete = $this->redis->sUnion(...$topicKeys);

            if (!is_array($keysToDelete) || empty($keysToDelete)) {
                // Only remove topic index keys if there are no data keys to delete.
                if (!empty($topicKeys)) {
                    $this->redis->del($topicKeys);
                }

                return true;
            }

            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $pipe->del($keysToDelete); // Deletes data keys.
            $pipe->del($topicKeys);    // Deletes topic index keys.
            $results = $pipe->exec();

            return is_array($results) && !empty($results);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('invalidateTopics', implode(',', $topicStrings), $e);
        }
    }

    /**
     * @return array<string>
     */
    private function performGetSubscribersForTopic(string $topic): array
    {
        try {
            $topicKey = $this->config->topicPrefix . $topic;
            $members = $this->redis->sMembers($topicKey);

            if (!is_array($members)) {
                return [];
            }

            // Remove the cache prefix from each key before returning
            $prefixLength = strlen($this->config->keyPrefix);

            return array_map(fn ($key) => substr($key, $prefixLength), $members);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('getSubscribersForTopic', $topic, $e);
        }
    }

    /**
     * @return array<string>
     */
    private function performGetSubscribedTopics(string $key): array
    {
        try {
            $fullKey = $this->config->keyPrefix . $key;
            $subscribedTopics = [];
            $iterator = null;

            // Use SCAN to avoid blocking the server with KEYS *
            while ($topicKeys = $this->redis->scan($iterator, $this->config->topicPrefix . '*')) {
                foreach ($topicKeys as $topicKey) {
                    if ($this->redis->sIsMember($topicKey, $fullKey)) {
                        // Remove prefix to return the clean topic name
                        $subscribedTopics[] = substr($topicKey, strlen($this->config->topicPrefix));
                    }
                }
            }

            return $subscribedTopics;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('getSubscribedTopics', $key, $e);
        }
    }
}
