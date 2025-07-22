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
 * Handles topic-based cache operations.
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

    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);

        if (empty($topics)) {
            throw InvalidArgumentException::invalidValue('Topics array cannot be empty');
        }

        $topicStrings = array_map('strval', $topics);

        $this->errorHandler->executeWithRetry(
            fn () => $this->performSubscribeTo($key, $topicStrings),
            $this->connectionManager,
            'subscribeTo',
            $key
        );
    }

    public function invalidateTopics(array $topics): bool
    {
        if (empty($topics)) {
            return true;
        }

        // Validação dos tipos Topic
        foreach ($topics as $topic) {
            if (!$topic instanceof Topic) {
                throw InvalidArgumentException::invalidValue('Expected Topic instance');
            }
        }

        $topicStrings = array_map(fn (Topic $topic) => (string) $topic, $topics);
        $this->validateTopicStrings($topicStrings);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTopics($topicStrings),
            $this->connectionManager,
            'invalidateTopics',
            implode(',', $topicStrings)
        );
    }

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
                throw CacheException::operationFailed('subscribeTo', $key, new \RuntimeException('Pipeline execution failed'));
            }
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('subscribeTo', $key, $e);
        }
    }

    private function performInvalidateTopics(array $topicStrings): bool
    {
        try {
            $topicKeys = array_map(fn (string $topic) => $this->config->topicPrefix . $topic, $topicStrings);

            if (empty($topicKeys)) {
                return true;
            }

            $keysToDelete = $this->redis->sUnion(...$topicKeys);

            if (!is_array($keysToDelete) || empty($keysToDelete)) {
                if (!empty($topicKeys)) {
                    $this->redis->del($topicKeys);
                }

                return true;
            }

            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $pipe->del($keysToDelete);
            $pipe->del($topicKeys);
            $results = $pipe->exec();

            return is_array($results) && !empty($results);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('invalidateTopics', implode(',', $topicStrings), $e);
        }
    }
}
