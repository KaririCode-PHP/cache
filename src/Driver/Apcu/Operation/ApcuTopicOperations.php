<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TopicValidationTrait;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Manages topic-based cache invalidation for APCu (emulated).
 * SRP: This class is only responsible for topic operations.
 */
final class ApcuTopicOperations
{
    use CacheKeyValidationTrait;
    use TopicValidationTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler
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

        $this->errorHandler->executeWithRetry(
            fn () => $this->performSubscribeTo($key, $topics),
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
        $this->validateTopics($topics);
        if (empty($topics)) {
            return true;
        }

        $topicNames = array_map(fn (Topic $topic) => (string) $topic, $topics);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTopics($topics),
            'invalidateTopics',
            implode(',', $topicNames)
        );
    }

    /**
     * Internal logic for the subscription operation.
     *
     * @param string $key the key to subscribe
     * @param array<Topic> $topics the topic objects
     */
    private function performSubscribeTo(string $key, array $topics): void
    {
        $fullKey = $this->config->keyPrefix . $key;

        foreach ($topics as $topic) {
            $topicName = (string) $topic;
            $topicKey = $this->config->getTopicIndexPrefix() . $topicName;

            $success = false;
            $subscribedKeys = apcu_fetch($topicKey, $success);

            if (!$success || !is_array($subscribedKeys)) {
                $subscribedKeys = [];
            }

            if (!in_array($fullKey, $subscribedKeys, true)) {
                $subscribedKeys[] = $fullKey;
                apcu_store($topicKey, $subscribedKeys);
            }
        }
    }

    /**
     * Internal logic for topic invalidation operation.
     *
     * @param array<Topic> $topics the topic objects
     *
     * @return bool true if the operation was successful
     */
    private function performInvalidateTopics(array $topics): bool
    {
        $keysToDelete = [];

        foreach ($topics as $topic) {
            $topicName = (string) $topic;
            $topicKey = $this->config->getTopicIndexPrefix() . $topicName;

            $success = false;
            $subscribedKeys = apcu_fetch($topicKey, $success);

            if ($success && is_array($subscribedKeys)) {
                $keysToDelete = array_merge($keysToDelete, $subscribedKeys);
            }
            apcu_delete($topicKey);
        }

        $keysToDelete = array_unique($keysToDelete);

        if (empty($keysToDelete)) {
            return true;
        }

        $failures = apcu_delete($keysToDelete);

        if (!is_array($failures)) {
            return false;
        }

        return empty($failures);
    }
}
