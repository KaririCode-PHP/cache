<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations for Memcached.
 *
 * This class emulates tagging functionality, which is not natively supported
 * by Memcached. It uses an index-based system with separate cache keys
 * to track relationships between items and tags. This approach is not atomic
 * and may have performance implications under heavy load.
 */
final class MemcachedTagOperations
{
    use CacheKeyValidationTrait;
    use TagValidationTrait;

    private const TAG_INDEX_PREFIX = 'tagindex:';

    public function __construct(
        private readonly \Memcached $memcached,
        private readonly MemcachedConfig $config,
        private readonly MemcachedErrorHandler $errorHandler,
        private readonly MemcachedConnectionManager $connectionManager,
        private readonly MemcachedMonitoringService $monitoringService,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    public function setWithTags(string $key, mixed $value, array $tags): bool
    {
        $this->validateKey($key);
        $this->validateTags($tags);

        // Usar ExpirationProvider para calcular TTL
        $expiration = $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performSetWithTags($key, $value, $tags, $expiration),
            $this->connectionManager,
            'setWithTags',
            $key
        );
    }

    /**
     * @param array<string|Tag> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTags($normalizedTags),
            $this->connectionManager,
            'invalidateTags'
        );
    }

    /**
     * @param array<Tag> $tags
     */
    public function deleteByTags(array $tags): bool
    {
        $this->validateTags($tags);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteByTags($tags),
            $this->connectionManager,
            'deleteByTags'
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

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetByTags($tags),
            $this->connectionManager,
            'getByTags'
        );

        return $result ?: [];
    }

    /**
     * @return array<Tag>
     */
    public function getTags(): array
    {
        $allKeys = $this->memcached->getAllKeys();
        if (false === $allKeys) {
            return [];
        }

        $prefix = $this->config->keyPrefix;
        $tagKeys = array_filter($allKeys, fn ($key) => str_starts_with($key, $prefix . self::TAG_INDEX_PREFIX));

        $tags = [];
        foreach ($tagKeys as $tagKey) {
            $tagName = substr($tagKey, strlen($prefix . self::TAG_INDEX_PREFIX));
            if (false !== $this->memcached->get($tagKey)) {
                try {
                    $tags[] = new Tag($tagName);
                } catch (\Throwable) {
                    // Skip invalid tags
                    continue;
                }
            }
        }

        return $tags;
    }

    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        // 1. Set the main cache item diretamente
        $ttl = $expiration ? $expiration->getTimestamp() - time() : 0;
        $fullKey = $this->config->keyPrefix . $key;

        if (!$this->memcached->set($fullKey, $value, $ttl)) {
            return false;
        }

        // 2. Tag the item
        $this->tagItem($key, $tags);

        return true;
    }

    /**
     * @param string $key the cache key
     * @param Tag[] $tags an array of tag strings
     */
    private function performTagItem(string $key, array $tags): void
    {
        $tagIndexKey = $this->getTagIndexKey($key);
        $this->memcached->set($tagIndexKey, $tags, 0);

        foreach ($tags as $tag) {
            $tagKey = $this->config->keyPrefix . (string) $tag;

            $result = $this->memcached->get($tagKey, null, \Memcached::GET_EXTENDED);

            $keys = [];
            $casToken = null;

            if (is_array($result)) {
                $keys = $result['value'] ?? [];
                $casToken = $result['cas'] ?? null;
            }

            if (!is_array($keys)) {
                $keys = [];
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }

            if (null === $casToken) {
                $this->memcached->add($tagKey, $keys, 0);
            } else {
                $this->memcached->cas($casToken, $tagKey, $keys, 0);
            }
        }
    }

    private function performInvalidateTags(array $tags): bool
    {
        $tagKeys = array_map(
            fn (Tag $tag): string => $this->config->keyPrefix . (string) $tag,
            $tags
        );

        $this->memcached->deleteMulti($tagKeys);

        return true;
    }

    private function getTagIndexKey(string $key): string
    {
        return $this->config->keyPrefix . self::TAG_INDEX_PREFIX . $key;
    }

    private function performDeleteByTags(array $tags): bool
    {
        $keys = $this->getKeysForTags($tags);

        if (empty($keys)) {
            return true;
        }

        // Add prefix to keys
        $prefixedKeys = array_map(
            fn (string $key): string => $this->config->keyPrefix . $key,
            $keys
        );

        // Delete the cache items
        $this->memcached->deleteMulti($prefixedKeys);

        // Clean up tag indexes
        foreach ($tags as $tag) {
            $tagKey = $this->config->keyPrefix . self::TAG_INDEX_PREFIX . (string) $tag;
            $this->memcached->delete($tagKey);
        }

        return true;
    }

    private function performGetByTags(array $tags): array
    {
        $keys = $this->getKeysForTags($tags);

        if (empty($keys)) {
            return [];
        }

        // Add prefix to keys for retrieval
        $prefixedKeys = array_map(
            fn (string $key): string => $this->config->keyPrefix . $key,
            $keys
        );

        $result = $this->memcached->getMulti($prefixedKeys);

        return is_array($result) ? $result : [];
    }

    private function tagItem(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $tagKey = $this->config->keyPrefix . self::TAG_INDEX_PREFIX . (string) $tag;

            // Get existing keys for this tag
            $existingKeys = $this->memcached->get($tagKey);
            if (!is_array($existingKeys)) {
                $existingKeys = [];
            }

            // Add the new key if not already present
            if (!in_array($key, $existingKeys, true)) {
                $existingKeys[] = $key;
            }

            // Store updated key list (no expiration for tag indexes)
            $this->memcached->set($tagKey, $existingKeys, 0);
        }
    }

    private function getKeysForTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        // Get keys for each tag
        $tagKeys = array_map(
            fn ($tag): string => $this->config->keyPrefix . self::TAG_INDEX_PREFIX . (string) $tag,
            $tags
        );

        $keysByTag = $this->memcached->getMulti($tagKeys);

        if (!is_array($keysByTag) || empty($keysByTag)) {
            return [];
        }

        // Find intersection of all key arrays (AND logic)
        $allKeys = array_values($keysByTag);
        $intersectedKeys = array_shift($allKeys);

        if (!is_array($intersectedKeys)) {
            return [];
        }

        foreach ($allKeys as $tagKeysArray) {
            if (!is_array($tagKeysArray)) {
                return []; // If any tag has no keys, intersection is empty
            }
            $intersectedKeys = array_intersect($intersectedKeys, $tagKeysArray);
        }

        return array_values($intersectedKeys);
    }
}
