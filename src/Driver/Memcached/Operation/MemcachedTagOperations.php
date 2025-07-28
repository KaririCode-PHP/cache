<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
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
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private MemcachedErrorHandler $errorHandler,
        private MemcachedConnectionManager $connectionManager,
        private MemcachedCrudOperations $crudOperations,
        private MemcachedBatchOperations $batchOperations
    ) {
    }

    /**
     * Sets a cache item with associated tags.
     *
     * @param array<string|Tag> $tags MUDANÇA: era array<string>, agora híbrido
     */
    public function setWithTags(
        string $key,
        mixed $value,
        array $tags,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $this->validateKey($key);

        $normalizedTags = $this->validateAndNormalizeTags($tags);

        if (!$this->crudOperations->set($key, $value, $expiration)) {
            return false;
        }

        $this->tagItem($key, $normalizedTags);

        return true;
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
     * @param array<string|Tag> $tags
     *
     * @return array<string, mixed>
     */
    public function getByTags(array $tags): array
    {
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        $keys = $this->getKeysForTags($normalizedTags);
        if (empty($keys)) {
            return [];
        }

        return $this->batchOperations->getMultiple($keys);
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

    /**
     * @param array<Tag> $tags MUDANÇA: Agora sempre Tag objects
     */
    private function tagItem(string $key, array $tags): void
    {
        $this->validateKey($key);
        $this->validateTags($tags);

        $this->errorHandler->executeWithRetry(
            fn () => $this->performTagItem($key, $tags),
            $this->connectionManager,
            'tagItem',
            $key
        );
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

    private function getKeysForTags(array $tags): array
    {
        $tagKeys = array_map(
            fn (Tag $tag): string => $this->config->keyPrefix . (string) $tag,
            $tags
        );

        $itemsByTag = $this->memcached->getMulti($tagKeys);

        if (empty($itemsByTag)) {
            return [];
        }

        // Find the intersection of all key arrays.
        $intersectedKeys = null;
        foreach ($itemsByTag as $keys) {
            if (!is_array($keys)) {
                continue;
            }
            if (null === $intersectedKeys) {
                $intersectedKeys = $keys;
            } else {
                $intersectedKeys = array_intersect($intersectedKeys, $keys);
            }
        }

        return $intersectedKeys ? array_values($intersectedKeys) : [];
    }

    private function getTagIndexKey(string $key): string
    {
        return $this->config->keyPrefix . self::TAG_INDEX_PREFIX . $key;
    }
}
