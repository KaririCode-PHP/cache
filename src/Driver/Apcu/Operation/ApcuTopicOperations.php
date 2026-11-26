<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Manages topic-based cache invalidation for APCu (emulated).
 *
 * This class is solely responsible for topic operations, including subscribing
 * keys to topics and invalidating keys by topic. It uses the ExpirationProvider
 * to apply a long-lived TTL to the topic indexes to prevent memory leaks over time.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuTopicOperations
{
    private readonly ValidationService $validator;

    /**
     * @param ApcuConfig $config configuration settings for APCu
     * @param ApcuErrorHandler $errorHandler error handling and retry logic
     * @param ExpirationProvider $expirationProvider strategy for TTL management
     */
    public function __construct(
        private readonly ApcuConfig $config,
        private readonly ApcuErrorHandler $errorHandler,
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
            fn () => $this->performInvalidateTopics($normalizedTopics),
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
        $fullKey = $this->config->keyPrefix . $key;
        $subscribedTopics = [];
        $prefix = $this->config->getTopicIndexPrefix();
        $iterator = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');

        foreach ($iterator as $item) {
            if (is_array($item) && isset($item['key'], $item['value']) && is_array($item['value'])) {
                if (in_array($fullKey, $item['value'], true)) {
                    $subscribedTopics[] = substr($item['key'], strlen($prefix));
                }
            }
        }

        return $subscribedTopics;
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
        $topicKey = $this->config->getTopicIndexPrefix() . $topic;
        $success = false;
        $subscribers = apcu_fetch($topicKey, $success);

        if (!$success || !is_array($subscribers)) {
            return [];
        }

        // Remove key prefix to return clean keys.
        $prefixLength = strlen($this->config->keyPrefix);

        return array_map(
            fn ($key) => substr((string) $key, $prefixLength),
            $subscribers
        );
    }

    /**
     * @param array<Topic> $topics
     */
    private function performSubscribeTo(string $key, array $topics): void
    {
        $fullKey = $this->config->keyPrefix . $key;

        foreach ($topics as $topic) {
            $topicKey = $this->config->getTopicIndexPrefix() . (string) $topic;
            $success = false;
            $subscribedKeys = apcu_fetch($topicKey, $success);

            if (!$success || !is_array($subscribedKeys)) {
                $subscribedKeys = [];
            }

            if (!in_array($fullKey, $subscribedKeys, true)) {
                $subscribedKeys[] = $fullKey;
                // Use a long TTL for topic indexes to prevent them from living forever.
                $expiration = $this->expirationProvider->calculateExpiration(86400); // 24 hours default
                $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
                apcu_store($topicKey, $subscribedKeys, $ttl);
            }
        }
    }

    /**
     * @param array<Topic> $topics
     */
    private function performInvalidateTopics(array $topics): bool
    {
        $keysToDelete = [];
        foreach ($topics as $topic) {
            $topicKey = $this->config->getTopicIndexPrefix() . (string) $topic;
            $success = false;
            $subscribedKeys = apcu_fetch($topicKey, $success);

            if ($success && is_array($subscribedKeys)) {
                $keysToDelete = array_merge($keysToDelete, $subscribedKeys);
            }
            // Delete the topic index.
            apcu_delete($topicKey);
        }

        $keysToDelete = array_unique($keysToDelete);
        if (empty($keysToDelete)) {
            return true;
        }

        // Delete all items subscribed to the topics.
        $failures = apcu_delete($keysToDelete);

        return empty($failures);
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
