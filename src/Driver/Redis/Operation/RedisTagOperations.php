<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations for the RedisDriver.
 *
 * This class is solely responsible for tag-related logic, using Redis Sets for
 * efficient index management. It internally uses the ExpirationProvider to
 * calculate TTLs for tagged items and their indexes, adhering to SRP and the
 * Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class RedisTagOperations
{
    use SerializationTrait;

    private const TAG_SET_PREFIX = 'tag:';
    private const TAG_EXPIRATION_BUFFER = 3600; // 1 hour buffer for tag index expiration

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
     * Stores an item with associated tags, calculating the TTL internally.
     *
     * @param string $key the key of the item
     * @param mixed $value the value to store
     * @param iterable<string|Tag> $tags the tags to associate
     *
     * @return bool true on success
     */
    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);
        $normalizedTags = $this->normalizeTags($tags);
        $this->validator->validateTags($normalizedTags);

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'setWithTags');

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetWithTags($key, $value, $normalizedTags, $finalExpiration),
            $this->connectionManager,
            'setWithTags',
            $key
        );
    }

    /**
     * Retrieves all items that have all of the given tags.
     *
     * @param iterable<string|Tag> $tags the tags to search for
     *
     * @return iterable<string, mixed> a map of keys to their values
     */
    public function getByTags(iterable $tags): iterable
    {
        $normalizedTags = $this->normalizeTags($tags);
        $this->validator->validateTags($normalizedTags);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetByTags($normalizedTags),
            $this->connectionManager,
            'getByTags'
        );

        return is_iterable($result) ? $result : [];
    }

    /**
     * Deletes all items associated with any of the given tags.
     *
     * @param iterable<string|Tag> $tags the tags to invalidate
     *
     * @return bool true on success
     */
    public function invalidateTags(iterable $tags): bool
    {
        $normalizedTags = $this->normalizeTags($tags);
        $this->validator->validateTags($normalizedTags);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTags($normalizedTags),
            $this->connectionManager,
            'invalidateTags'
        );
    }

    /**
     * Retrieves all unique tags stored in the cache.
     *
     * @return iterable<Tag> a list of Tag objects
     */
    public function getTags(): iterable
    {
        $iterator = null;
        $prefix = $this->config->keyPrefix . self::TAG_SET_PREFIX;
        $prefixLength = strlen($prefix);

        // Use SCAN to avoid blocking the server with KEYS *.
        while ($keys = $this->redis->scan($iterator, $prefix . '*')) {
            foreach ($keys as $key) {
                yield new Tag(substr($key, $prefixLength));
            }
        }
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serializedValue = $this->serialize($value);

        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        if ($expiration) {
            $ttl = max(1, $expiration->getTimestamp() - time());
            $pipeline->setex($fullKey, $ttl, $serializedValue);
            $tagTtl = $ttl + self::TAG_EXPIRATION_BUFFER;
        } else {
            $pipeline->set($fullKey, $serializedValue);
            $tagTtl = null;
        }

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
     */
    private function performInvalidateTags(array $tags): bool
    {
        $keysToDelete = $this->getKeysForTags($tags);
        if (empty($keysToDelete)) {
            return true;
        }

        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        $pipeline->del($keysToDelete); // Delete the actual cache items.

        // Delete the tag index keys themselves.
        foreach ($tags as $tag) {
            $pipeline->del($this->getTagKey($tag));
        }

        $pipeline->exec();

        return true;
    }

    /**
     * @param array<Tag> $tags
     *
     * @return iterable<string, mixed>
     */
    private function performGetByTags(array $tags): iterable
    {
        $keys = $this->getKeysForTags($tags);
        if (empty($keys)) {
            return [];
        }

        $values = $this->redis->mget($keys);
        if (!is_array($values)) {
            return [];
        }

        $results = [];
        $prefixLength = strlen($this->config->keyPrefix);
        foreach ($keys as $index => $fullKey) {
            if (isset($values[$index]) && false !== $values[$index]) {
                $originalKey = substr($fullKey, $prefixLength);
                $results[$originalKey] = $this->unserialize($values[$index]);
            }
        }

        return $results;
    }

    /**
     * @param array<Tag> $tags
     *
     * @return array<string> a list of full, prefixed keys
     */
    private function getKeysForTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $tagKeys = array_map(fn (Tag $tag) => $this->getTagKey($tag), $tags);

        // Get intersection of all tag sets to find keys that have ALL tags.
        $keys = $this->redis->sInter($tagKeys);

        return is_array($keys) ? $keys : [];
    }

    private function getTagKey(Tag $tag): string
    {
        return $this->config->keyPrefix . self::TAG_SET_PREFIX . (string) $tag;
    }

    /**
     * @param iterable<string|Tag> $tags
     *
     * @return array<Tag>
     */
    private function normalizeTags(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $normalized[] = $tag instanceof Tag ? $tag : new Tag((string) $tag);
        }

        return $normalized;
    }
}
