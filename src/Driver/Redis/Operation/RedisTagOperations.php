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
use KaririCode\Cache\Validation\Trait\ExpirationCalculatorTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;

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
    use ExpirationCalculatorTrait;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager
    ) {
    }

    /**
     * Sets a cache value with associated tags.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param array<int, string> $tags Array of tag strings
     * @param \DateInterval|int|null $ttl Time to live
     *
     * @throws InvalidArgumentException When tags are empty or invalid
     * @throws CacheException On Redis operation failure
     *
     * @return bool True on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTags($tags);

        if (empty($tags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        // Type-safe execution with explicit bool return
        $result = $this->errorHandler->executeWithRetry(
            fn (): bool => $this->performSetWithTags($key, $value, $tags, $ttl),
            $this->connectionManager,
            'setWithTags',
            $key
        );

        return is_bool($result) ? $result : false;
    }

    /**
     * Invalidates all cache entries associated with given tags.
     *
     * @param array<int, string> $tags Array of tag strings to invalidate
     *
     * @return bool True on success, false on failure
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
     * Retrieves all cache entries associated with given tags.
     *
     * @param array<int, string> $tags Array of tag strings
     *
     * @return array<string, mixed> Associative array of key-value pairs
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
     * Retrieves all available tags.
     *
     * @return array<int, string> Array of tag strings
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
     * Internal method to perform the actual set operation with tags.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param array<int, string> $tags Array of tags
     * @param \DateInterval|int|null $ttl Time to live
     *
     * @throws SerializationException When serialization fails
     * @throws CacheException When Redis operation fails
     *
     * @return bool Success status
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
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            // Set the main key-value pair
            if ($expiration) {
                $ttlSeconds = max(1, $expiration->getTimestamp() - time());
                if ($ttlSeconds <= 0) {
                    throw InvalidArgumentException::invalidTtl($ttlSeconds);
                }
                $pipe->setex($fullKey, $ttlSeconds, $serialized);
            } else {
                $pipe->set($fullKey, $serialized);
            }

            // Associate key with tags
            foreach ($tags as $tag) {
                $tagKey = $this->config->tagPrefix . (string) $tag;
                $pipe->sAdd($tagKey, $fullKey);

                // Set expiration for tag keys (with buffer to prevent race conditions)
                if ($expiration) {
                    $pipe->expireAt($tagKey, $expiration->getTimestamp() + 3600);
                }
            }

            $results = $pipe->exec();

            // Type-safe result validation
            return is_array($results) && !empty($results) && !in_array(false, $results, true);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('setWithTags', $key, $e);
        }
    }

    /**
     * Internal method to perform tag invalidation.
     *
     * @param array<int, string> $tags Array of tag strings
     *
     * @return bool Success status
     */
    private function performInvalidateTags(array $tags): bool
    {
        try {
            $tagKeys = array_map(fn (string $tag): string => $this->config->tagPrefix . $tag, $tags);

            if (empty($tagKeys)) {
                return true;
            }

            // Get all keys associated with these tags
            $keysToDelete = $this->redis->sUnion(...$tagKeys);

            if (!is_array($keysToDelete) || empty($keysToDelete)) {
                // Only delete tag keys if they exist
                if (!empty($tagKeys)) {
                    $this->redis->del($tagKeys);
                }

                return true;
            }

            // Use pipeline for atomic operation
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $pipe->del($keysToDelete);  // Delete all keys associated with tags
            $pipe->del($tagKeys);       // Delete tag keys themselves
            $results = $pipe->exec();

            return is_array($results) && !empty($results);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('invalidateTags', implode(',', $tags), $e);
        }
    }

    /**
     * Internal method to retrieve entries by tags.
     *
     * @param array<int, string> $tags Array of tag strings
     *
     * @return array<string, mixed> Associative array of key-value pairs
     */
    private function performGetByTags(array $tags): array
    {
        try {
            $tagKeys = array_map(fn (string $tag): string => $this->config->tagPrefix . $tag, $tags);

            if (empty($tagKeys)) {
                return [];
            }

            // Get intersection of all tag sets (keys that have ALL specified tags)
            $itemKeys = $this->redis->sInter(...$tagKeys);

            if (!is_array($itemKeys) || empty($itemKeys)) {
                return [];
            }

            // Retrieve values for all keys
            $rawValues = $this->redis->mget($itemKeys);
            if (!is_array($rawValues)) {
                return [];
            }

            $results = [];
            foreach ($itemKeys as $index => $fullKey) {
                $value = $rawValues[$index] ?? null;
                if (null !== $value && false !== $value && is_string($value)) {
                    $originalKey = str_replace($this->config->keyPrefix, '', $fullKey);
                    try {
                        $results[$originalKey] = $this->unserialize($value);
                    } catch (\Throwable) {
                        // Skip corrupted entries - fail fast principle
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
     * Internal method to retrieve all available tags.
     *
     * @return array<int, string> Array of tag strings
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

    /**
     * Validates tag strings for security and format compliance.
     *
     * @param array<int, string> $tags
     *
     * @throws InvalidArgumentException
     */
    private function validateTagStrings(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || empty(trim($tag))) {
                throw InvalidArgumentException::invalidTagFormat($tag);
            }
        }
    }
}
