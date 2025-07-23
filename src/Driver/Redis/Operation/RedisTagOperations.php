<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Exception\SerializationException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations with type safety and performance optimization.
 *
 * @see https://redis.io/docs/manual/pipelining/ Redis Pipeline Documentation
 */
final class RedisTagOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use ExpirationManagementTrait;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager
    ) {
    }

    /**
     * @param array<int, string> $tags
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTagStrings($tags);

        if (empty($tags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        $result = $this->errorHandler->executeWithRetry(
            fn (): bool => $this->performSetWithTags($key, $value, $tags, $ttl),
            $this->connectionManager,
            'setWithTags',
            $key
        );

        return is_bool($result) ? $result : false;
    }

    /**
     * @param array<int, string> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTagStrings($tags);

        if (empty($tags)) {
            return true;
        }

        $result = $this->errorHandler->executeWithRetry(
            fn (): bool => $this->performInvalidateTags($tags),
            $this->connectionManager,
            'invalidateTags',
            implode(',', $tags)
        );

        return is_bool($result) ? $result : false;
    }

    /**
     * @param array<int, string> $tags
     *
     * @return array<string, mixed>
     */
    public function getByTags(array $tags): array
    {
        $this->validateTagStrings($tags);

        if (empty($tags)) {
            return [];
        }

        $result = $this->errorHandler->executeWithRetry(
            fn (): array => $this->performGetByTags($tags),
            $this->connectionManager,
            'getByTags',
            implode(',', $tags)
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        $result = $this->errorHandler->executeWithRetry(
            fn (): array => $this->performGetTags(),
            $this->connectionManager,
            'getTags',
            '*'
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<int, string> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        try {
            $serialized = $this->serialize($value);
        } catch (\Throwable $e) {
            throw SerializationException::serializationFailed($value, $e);
        }

        $expiration = $this->calculateExpiration($ttl);

        try {
            // Use a pipeline to ensure all commands are sent in a single batch for atomicity.
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            if ($expiration) {
                $ttlSeconds = max(1, $expiration->getTimestamp() - time());

                $pipe->setex($fullKey, (int) $ttlSeconds, $serialized);
            } else {
                $pipe->set($fullKey, $serialized);
            }

            foreach ($tags as $tag) {
                $tagKey = $this->config->tagPrefix . $tag;
                $pipe->sAdd($tagKey, $fullKey);

                // Set expiration for tag sets to prevent them from living forever.
                if ($expiration) {
                    $pipe->expireAt($tagKey, $expiration->getTimestamp() + 3600); // 1-hour buffer
                }
            }

            $results = $pipe->exec();

            return is_array($results) && !empty($results) && !in_array(false, $results, true);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('setWithTags', $key, $e);
        }
    }

    /**
     * @param array<int, string> $tags
     */
    private function performInvalidateTags(array $tags): bool
    {
        try {
            $tagKeys = array_map(fn (string $tag): string => $this->config->tagPrefix . $tag, $tags);
            $keysToDelete = $this->redis->sUnion(...$tagKeys);

            if (!is_array($keysToDelete) || empty($keysToDelete)) {
                $this->redis->del($tagKeys);

                return true;
            }

            // Atomically delete all data keys and the tag index keys.
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $pipe->del($keysToDelete);
            $pipe->del($tagKeys);
            $results = $pipe->exec();

            return is_array($results) && !empty($results);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('invalidateTags', implode(',', $tags), $e);
        }
    }

    /**
     * @param array<int, string> $tags
     *
     * @return array<string, mixed>
     */
    private function performGetByTags(array $tags): array
    {
        try {
            $tagKeys = array_map(fn (string $tag): string => $this->config->tagPrefix . $tag, $tags);

            // Get the intersection of all tag sets (keys that have ALL specified tags).
            $itemKeys = $this->redis->sInter(...$tagKeys);

            if (!is_array($itemKeys) || empty($itemKeys)) {
                return [];
            }

            /** @var list<string|false> $rawValues */
            $rawValues = $this->redis->mget($itemKeys);
            if (!is_array($rawValues)) {
                return [];
            }

            $results = [];
            foreach ($itemKeys as $index => $fullKey) {
                $value = $rawValues[$index] ?? null;

                if (is_string($value)) {
                    $originalKey = str_replace($this->config->keyPrefix, '', $fullKey);
                    try {
                        $results[$originalKey] = $this->unserialize($value);
                    } catch (\Throwable) {
                        // Skip corrupted entries.
                        continue;
                    }
                }
            }

            return $results;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('getByTags', implode(',', $tags), $e);
        }
    }

    /**
     * @return array<int, string>
     */
    private function performGetTags(): array
    {
        try {
            $tagKeys = $this->redis->keys($this->config->tagPrefix . '*');
            if (!is_array($tagKeys)) {
                return [];
            }

            return array_map(
                fn (string $key): string => str_replace($this->config->tagPrefix, '', $key),
                $tagKeys
            );
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('getTags', '*', $e);
        }
    }
}
