<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Operation\Factory\TagNormalizer;
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
     * @param array<string|Tag> $tags
     */
    public function setWithTags(
        string $key,
        mixed $value,
        array $tags,
        \DateInterval|int|null $ttl = null
    ): bool {
        $this->validateKey($key);
        $this->validateValue($value);

        // MUDANÇA: Normalização híbrida
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        $result = $this->errorHandler->executeWithRetry(
            fn (): bool => $this->performSetWithTags($key, $value, $normalizedTags, $ttl),
            $this->connectionManager,
            'setWithTags',
            $key
        );

        return is_bool($result) ? $result : false;
    }

    /**
     * @param array<string|Tag> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        $result = $this->errorHandler->executeWithRetry(
            fn (): bool => $this->performInvalidateTags($normalizedTags),
            $this->connectionManager,
            'invalidateTags',
            implode(',', TagNormalizer::toStrings($normalizedTags))
        );

        return is_bool($result) ? $result : false;
    }

    /**
     * @param array<string|Tag> $tags
     */
    public function getByTags(array $tags): array
    {
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        $result = $this->errorHandler->executeWithRetry(
            fn (): array => $this->performGetByTags($normalizedTags),
            $this->connectionManager,
            'getByTags',
            implode(',', TagNormalizer::toStrings($normalizedTags))
        );

        return is_array($result) ? $result : [];
    }

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

    private function performSetWithTags(
        string $key,
        mixed $value,
        array $tags,
        \DateInterval|int|null $ttl
    ): bool {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $expiration = $this->calculateExpiration($ttl);

        try {
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            if ($expiration) {
                $ttlSeconds = max(1, $expiration->getTimestamp() - time());
                $pipe->setex($fullKey, $ttlSeconds, $serialized);
            } else {
                $pipe->set($fullKey, $serialized);
            }

            foreach ($tags as $tag) {
                $tagKey = $this->config->tagPrefix . $tag;
                $pipe->sAdd($tagKey, $fullKey);
                if ($expiration) {
                    $pipe->expireAt($tagKey, $expiration->getTimestamp() + 3600);
                }
            }

            $results = $pipe->exec();

            return is_array($results) && !in_array(false, $results, true);
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('setWithTags', $key, $e);
        }
    }

    private function performInvalidateTags(array $tags): bool
    {
        try {
            $tagKeys = array_map(fn (Tag $tag): string => $this->config->tagPrefix . $tag, $tags);
            $keysToDelete = $this->redis->sUnion(...$tagKeys);

            if (empty($keysToDelete)) {
                if (!empty($tagKeys)) {
                    $this->redis->del($tagKeys);
                }

                return true;
            }

            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $pipe->del($keysToDelete);
            $pipe->del($tagKeys);
            $pipe->exec();

            return true;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('invalidateTags', implode(',', array_map('strval', $tags)), $e);
        }
    }

    private function performGetByTags(array $tags): array
    {
        try {
            $tagKeys = array_map(fn (Tag $tag): string => $this->config->tagPrefix . $tag, $tags);
            $itemKeys = $this->redis->sInter(...$tagKeys);

            if (empty($itemKeys)) {
                return [];
            }

            $rawValues = $this->redis->mget($itemKeys);
            if (!is_array($rawValues)) {
                return [];
            }

            $valueMap = array_combine($itemKeys, $rawValues);

            $results = [];
            foreach ($valueMap as $fullKey => $value) {
                if (is_string($value)) {
                    $originalKey = str_replace($this->config->keyPrefix, '', (string) $fullKey);
                    try {
                        $results[$originalKey] = $this->unserialize($value);
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }

            return $results;
        } catch (\RedisException $e) {
            throw CacheException::operationFailed('getByTags', implode(',', array_map('strval', $tags)), $e);
        }
    }

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
