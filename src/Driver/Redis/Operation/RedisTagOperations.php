<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations with type safety and performance optimization.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class RedisTagOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;

    private const TAG_SET_PREFIX = 'tag:';
    private const TAG_EXPIRATION_BUFFER = 3600; // 1 hour buffer for tag expiration

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * @param array<Tag> $tags
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTags($tags);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetWithTags($key, $value, $tags, $finalExpiration),
            $this->connectionManager,
            'setWithTags',
            $key
        );
    }

    /**
     * @param array<Tag> $tags
     *
     * @return array<string>
     */
    public function getByTags(array $tags): array
    {
        $this->validateTags($tags);

        return (array) $this->errorHandler->executeWithRetry(
            fn () => $this->performGetByTags($tags),
            $this->connectionManager,
            'getByTags'
        );
    }

    /**
     * @param array<Tag> $tags
     */
    public function deleteByTags(array $tags): bool
    {
        $this->validateTags($tags);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteByTags($tags),
            $this->connectionManager,
            'deleteByTags'
        );
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serializedValue = $this->serialize($value);

        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        // Set the main cache entry
        if ($expiration) {
            $ttl = max(1, $expiration->getTimestamp() - time());
            $pipeline->setex($fullKey, $ttl, $serializedValue);

            // Set tag expiration with buffer to ensure tags outlive the cached item
            $tagTtl = $ttl + self::TAG_EXPIRATION_BUFFER;
        } else {
            $pipeline->set($fullKey, $serializedValue);
            $tagTtl = null;
        }

        // Associate key with tags
        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $pipeline->sAdd($tagKey, $fullKey);

            if ($tagTtl) {
                $pipeline->expire($tagKey, $tagTtl);
            }
        }

        $results = $pipeline->exec();

        return is_array($results) && !in_array(false, $results, true);
    }

    /**
     * @param array<Tag> $tags
     *
     * @return array<string>
     */
    private function performGetByTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $tagKeys = array_map(fn (Tag $tag) => $this->getTagKey($tag), $tags);

        // Get intersection of all tag sets to find keys that have ALL tags
        $keys = $this->redis->sInter($tagKeys);

        if (!is_array($keys) || empty($keys)) {
            return [];
        }

        // Remove prefix from keys to return original cache keys
        $prefixLength = strlen($this->config->keyPrefix);

        return array_map(
            fn (string $key) => substr($key, $prefixLength),
            $keys
        );
    }

    /**
     * @param array<Tag> $tags
     */
    private function performDeleteByTags(array $tags): bool
    {
        $keys = $this->performGetByTags($tags);

        if (empty($keys)) {
            return true;
        }

        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        // Delete all keys associated with the tags
        foreach ($keys as $key) {
            $fullKey = $this->config->keyPrefix . $key;
            $pipeline->del($fullKey);
        }

        // Remove keys from tag sets
        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            foreach ($keys as $key) {
                $fullKey = $this->config->keyPrefix . $key;
                $pipeline->sRem($tagKey, $fullKey);
            }
        }

        $results = $pipeline->exec();

        return is_array($results);
    }

    private function getTagKey(Tag $tag): string
    {
        return $this->config->keyPrefix . self::TAG_SET_PREFIX . (string) $tag;
    }
}
