<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations for the MemcachedDriver.
 *
 * This class emulates tagging functionality, which is not natively supported
 * by Memcached. It uses an index-based system with separate cache keys
 * to track relationships between items and tags. It internally uses the
 * ExpirationProvider to manage TTLs for both items and tag indexes.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class MemcachedTagOperations
{
    private const TAG_INDEX_PREFIX = 'tagindex:';

    private readonly ValidationService $validator;

    /**
     * @param \Memcached $memcached the Memcached client instance
     * @param MemcachedConfig $config configuration settings
     * @param MemcachedErrorHandler $errorHandler error handling and retry logic
     * @param MemcachedConnectionManager $connectionManager connection health management
     * @param MemcachedMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Memcached $memcached,
        private readonly MemcachedConfig $config,
        private readonly MemcachedErrorHandler $errorHandler,
        private readonly MemcachedConnectionManager $connectionManager,
        private readonly MemcachedMonitoringService $monitoringService,
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
     * Invalidates all cache items associated with the given tags.
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
     * Retrieves all unique tags stored in the cache.
     *
     * @return iterable<Tag> a list of Tag objects
     */
    public function getTags(): iterable
    {
        // This is an expensive operation on Memcached and should be used sparingly.
        // It relies on server commands that may not be available or performant.
        // A more robust solution would require a separate index.
        // For now, we return an empty array as a safe default.
        return [];
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        if (!$this->memcached->set($fullKey, $value, $ttl)) {
            return false;
        }

        $this->monitoringService->recordWrite();

        // Update tag indexes
        foreach ($tags as $tag) {
            $this->addKeyToTagIndex($tag, $key, $ttl);
        }

        return true;
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

        $prefixedKeys = array_map(fn ($k) => $this->config->keyPrefix . $k, $keysToDelete);
        $this->memcached->deleteMulti($prefixedKeys);

        // Also delete the tag index keys themselves
        $tagIndexKeys = array_map(fn (Tag $tag) => $this->getTagIndexKey($tag), $tags);
        $this->memcached->deleteMulti($tagIndexKeys);

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

        $prefixedKeys = array_map(fn ($k) => $this->config->keyPrefix . $k, $keys);
        $values = $this->memcached->getMulti($prefixedKeys);

        if (!is_array($values)) {
            return [];
        }

        $result = [];
        $prefixLength = strlen($this->config->keyPrefix);
        foreach ($values as $prefixedKey => $value) {
            $originalKey = substr($prefixedKey, $prefixLength);
            $result[$originalKey] = $value;
        }

        return $result;
    }

    /**
     * @param array<Tag> $tags
     *
     * @return array<string>
     */
    private function getKeysForTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $keySets = [];
        foreach ($tags as $tag) {
            $tagIndexKey = $this->getTagIndexKey($tag);
            $keys = $this->memcached->get($tagIndexKey);
            if (!is_array($keys)) {
                return []; // If any tag has no keys, the intersection is empty
            }
            $keySets[] = $keys;
        }

        return count($keySets) > 1 ? array_intersect(...$keySets) : ($keySets[0] ?? []);
    }

    private function addKeyToTagIndex(Tag $tag, string $key, int $itemTtl): void
    {
        $tagIndexKey = $this->getTagIndexKey($tag);
        $casToken = 0.0; // Must be a float for cas
        $keys = $this->memcached->get($tagIndexKey, null, \Memcached::GET_EXTENDED);

        if (is_array($keys) && isset($keys['cas'])) {
            $casToken = $keys['cas'];
            $currentKeys = $keys['value'];
        } else {
            $currentKeys = [];
        }

        if (!is_array($currentKeys)) {
            $currentKeys = [];
        }

        if (!in_array($key, $currentKeys, true)) {
            $currentKeys[] = $key;
            // Tag indexes should live longer than the items they track
            $tagTtl = $itemTtl > 0 ? $itemTtl + 3600 : 86400; // 1 hour buffer or 24h default

            if ($casToken > 0) {
                $this->memcached->cas($casToken, $tagIndexKey, $currentKeys, $tagTtl);
            } else {
                $this->memcached->set($tagIndexKey, $currentKeys, $tagTtl);
            }
        }
    }

    private function getTagIndexKey(Tag $tag): string
    {
        return $this->config->keyPrefix . self::TAG_INDEX_PREFIX . (string) $tag;
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
