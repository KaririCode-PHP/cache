<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;

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
     * @param array<string> $tags
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration = null): bool
    {
        if (!$this->crudOperations->set($key, $value, $expiration)) {
            return false;
        }

        $this->tagItem($key, $tags);

        return true;
    }

    /**
     * @param array<string> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTagStrings($tags);
        if (empty($tags)) {
            return true;
        }

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTags($tags),
            $this->connectionManager,
            'invalidateTags'
        );
    }

    /**
     * @param array<string> $tags
     *
     * @return array<string, mixed>
     */
    public function getByTags(array $tags): array
    {
        $this->validateTagStrings($tags);
        if (empty($tags)) {
            return [];
        }

        $keys = $this->getKeysForTags($tags);
        if (empty($keys)) {
            return [];
        }

        // Fetch the items for the intersected keys.
        return $this->batchOperations->getMultiple($keys);
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        // WARNING: This is a very inefficient operation on Memcached and should be used with caution in production.
        $allKeys = $this->memcached->getAllKeys();
        if (false === $allKeys) {
            return [];
        }

        $prefix = $this->config->keyPrefix;
        $tagKeys = preg_grep('/^' . preg_quote($prefix, '/') . '(?!' . self::TAG_INDEX_PREFIX . ').*/', $allKeys);

        return $tagKeys ? array_values(array_map(fn ($key) => str_replace($prefix, '', $key), $tagKeys)) : [];
    }

    /**
     * @param array<string> $tags
     */
    private function tagItem(string $key, array $tags): void
    {
        $this->validateKey($key);
        $this->validateTagStrings($tags);

        $this->errorHandler->executeWithRetry(
            fn () => $this->performTagItem($key, $tags),
            $this->connectionManager,
            'tagItem',
            $key
        );
    }

    /**
     * @param array<string> $tags
     */
    private function performTagItem(string $key, array $tags): void
    {
        $tagIndexKey = $this->getTagIndexKey($key);
        // Store an index of which tags this key belongs to. Expires with the item itself (logic managed by higher layers).
        $this->memcached->set($tagIndexKey, $tags, 0);

        foreach ($tags as $tag) {
            $tagKey = $this->config->keyPrefix . $tag;

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

    /**
     * @param array<string> $tags
     */
    private function performInvalidateTags(array $tags): bool
    {
        $tagKeys = array_map(fn (string $tag) => $this->config->keyPrefix . $tag, $tags);
        $this->memcached->deleteMulti($tagKeys);

        return true;
    }

    /**
     * @param array<string> $tags
     *
     * @return array<string>
     */
    private function getKeysForTags(array $tags): array
    {
        $tagKeys = array_map(fn (string $tag) => $this->config->keyPrefix . $tag, $tags);
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
