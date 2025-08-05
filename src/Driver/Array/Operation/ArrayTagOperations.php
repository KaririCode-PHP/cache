<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Contract\DataStructure\Map;
use KaririCode\DataStructure\Set\TreeSet;

/**
 * Handles tag-based cache operations for ArrayDriver with ExpirationProvider support.
 *
 * This class manages tag-based cache operations with proper TTL management
 * using the Strategy Pattern through ExpirationProvider. Uses KaririCode\DataStructure
 * components for efficient storage management.
 *
 * Design Patterns Applied:
 * - Strategy Pattern: ExpirationProvider for TTL management
 * - Single Responsibility: Handles only tag operations
 * - Dependency Injection: All dependencies injected via constructor
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayTagOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;

    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param Map $storage Main cache storage
     * @param Map $expirations Expiration timestamps storage
     * @param Map $tagIndex Tag to keys mapping
     * @param callable $removeFromIndexesCallback Callback to remove keys from all indexes
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     */
    public function __construct(
        private readonly Map $storage,
        private readonly Map $expirations,
        private readonly Map $tagIndex,
        private readonly mixed $removeFromIndexesCallback,
        private readonly ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * Sets a cache item with associated tags and proper TTL handling.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param iterable<string|Tag> $tags Tags to associate with the cache item
     * @param \DateTimeInterface|null $expiration Optional expiration time
     *
     * @return bool true if the item was successfully stored
     */
    public function setWithTags(string $key, mixed $value, iterable $tags, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $normalizedTags = $this->normalizeTags($tags);
        if (empty($normalizedTags)) {
            return false; // No tags provided
        }

        $this->validateTags($normalizedTags);

        // Calculate expiration using ExpirationProvider
        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('setWithTags')
        );

        // Store the value
        $this->storage->put($key, $value);

        // Set expiration if provided
        if (null !== $finalExpiration) {
            $this->expirations->put($key, $finalExpiration->getTimestamp());
        }

        // Associate with tags
        foreach ($normalizedTags as $tag) {
            $this->addTagAssociation((string) $tag, $key);
        }

        return true;
    }

    /**
     * Retrieves cache items associated with any of the specified tags.
     *
     * @param iterable<string|Tag> $tags Tags to search for
     *
     * @return iterable<string, mixed> Array of key-value pairs for items with the specified tags
     */
    public function getByTags(iterable $tags): iterable
    {
        $normalizedTags = $this->normalizeTags($tags);
        if (empty($normalizedTags)) {
            return [];
        }

        $this->validateTags($normalizedTags);

        $allKeys = new TreeSet();

        // Collect all keys from all tags
        foreach ($normalizedTags as $tag) {
            $tagName = (string) $tag;
            if ($this->tagIndex->containsKey($tagName)) {
                $keysSet = $this->tagIndex->get($tagName);
                if ($keysSet instanceof TreeSet) {
                    foreach ($keysSet->getItems() as $taggedKey) {
                        if (!$this->isExpired($taggedKey)) {
                            $allKeys->add($taggedKey);
                        }
                    }
                }
            }
        }

        // Get values for all found keys
        $result = [];
        foreach ($allKeys->getItems() as $key) {
            if ($this->storage->containsKey($key)) {
                $result[$key] = $this->storage->get($key);
            }
        }

        return $result;
    }

    /**
     * Invalidates all cache items associated with the specified tags.
     *
     * @param iterable<string|Tag> $tags Tags to invalidate
     *
     * @return bool true if the invalidation was successful
     */
    public function invalidateTags(iterable $tags): bool
    {
        $normalizedTags = $this->normalizeTags($tags);
        if (empty($normalizedTags)) {
            return true; // No tags to invalidate
        }

        $this->validateTags($normalizedTags);

        $keysToDelete = new TreeSet();

        // Collect all keys from all tags
        foreach ($normalizedTags as $tag) {
            $tagName = (string) $tag;
            if ($this->tagIndex->containsKey($tagName)) {
                $keysSet = $this->tagIndex->get($tagName);
                if ($keysSet instanceof TreeSet) {
                    foreach ($keysSet->getItems() as $key) {
                        $keysToDelete->add($key);
                    }
                }

                // Clear the tag index
                $this->tagIndex->remove($tagName);
            }
        }

        // Delete all tagged keys using the callback
        foreach ($keysToDelete->getItems() as $key) {
            if ($this->removeFromIndexesCallback) {
                call_user_func($this->removeFromIndexesCallback, $key);
            }
        }

        return true;
    }

    /**
     * Gets all keys associated with a specific tag.
     *
     * @param string|Tag $tag The tag to search for
     *
     * @return array<string> List of keys associated with the tag
     */
    public function getTaggedKeys(string|Tag $tag): array
    {
        $tagName = $tag instanceof Tag ? (string) $tag : $tag;

        if (!$this->tagIndex->containsKey($tagName)) {
            return [];
        }

        $keysSet = $this->tagIndex->get($tagName);
        if (!$keysSet instanceof TreeSet) {
            return [];
        }

        // Filter out expired keys
        $validKeys = [];
        foreach ($keysSet->getItems() as $key) {
            if (!$this->isExpired($key)) {
                $validKeys[] = $key;
            }
        }

        return $validKeys;
    }

    /**
     * Gets all available tags in the cache.
     *
     * @return iterable<Tag> List of all Tag objects
     */
    public function getTags(): iterable
    {
        $tags = [];
        foreach ($this->tagIndex->keys() as $tagName) {
            $tags[] = new Tag($tagName);
        }

        return $tags;
    }

    /**
     * Checks if a tag exists in the cache.
     *
     * @param string|Tag $tag The tag to check
     *
     * @return bool true if the tag exists
     */
    public function hasTag(string|Tag $tag): bool
    {
        $tagName = $tag instanceof Tag ? (string) $tag : $tag;

        return $this->tagIndex->containsKey($tagName);
    }

    /**
     * Gets the count of keys associated with a tag.
     *
     * @param string|Tag $tag The tag to count
     *
     * @return int Number of keys associated with the tag
     */
    public function getTaggedKeysCount(string|Tag $tag): int
    {
        $tagName = $tag instanceof Tag ? (string) $tag : $tag;

        if (!$this->tagIndex->containsKey($tagName)) {
            return 0;
        }

        $keysSet = $this->tagIndex->get($tagName);
        if (!$keysSet instanceof TreeSet) {
            return 0;
        }

        return $keysSet->size();
    }

    /**
     * Adds a tag association for a key.
     *
     * @param string $tag The tag name
     * @param string $key The cache key
     */
    public function addTagAssociation(string $tag, string $key): void
    {
        if (!$this->tagIndex->containsKey($tag)) {
            $this->tagIndex->put($tag, new TreeSet());
        }

        $keysSet = $this->tagIndex->get($tag);
        if ($keysSet instanceof TreeSet) {
            $keysSet->add($key);
        }
    }

    /**
     * Removes a tag association for a key.
     *
     * @param string $tag The tag name
     * @param string $key The cache key
     */
    public function removeTagAssociation(string $tag, string $key): void
    {
        if ($this->tagIndex->containsKey($tag)) {
            $keysSet = $this->tagIndex->get($tag);
            if ($keysSet instanceof TreeSet) {
                $keysSet->remove($key);

                // Remove empty tag index
                if ($keysSet->isEmpty()) {
                    $this->tagIndex->remove($tag);
                }
            }
        }
    }

    /**
     * Gets all keys tagged with the provided tags using array format.
     *
     * @param iterable<string|Tag> $tags
     *
     * @return array<string> List of keys
     */
    public function getKeysByTag(iterable $tags): array
    {
        $keys = new TreeSet();

        foreach ($tags as $tag) {
            $tagStr = (string) $tag;
            if ($this->tagIndex->containsKey($tagStr)) {
                $keysSet = $this->tagIndex->get($tagStr);
                if ($keysSet instanceof TreeSet) {
                    foreach ($keysSet->getItems() as $key) {
                        if (!$this->isExpired($key)) {
                            $keys->add($key);
                        }
                    }
                }
            }
        }

        return $keys->getItems();
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Normalizes tags to Tag objects.
     *
     * @param iterable<string|Tag> $tags
     *
     * @return array<Tag>
     */
    private function normalizeTags(iterable $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $normalized[] = new Tag($tag);
            } elseif ($tag instanceof Tag) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
    }

    /**
     * Checks if a key is expired.
     */
    private function isExpired(string $key): bool
    {
        if (!$this->expirations->containsKey($key)) {
            return false;
        }

        $expiration = $this->expirations->get($key);

        return is_int($expiration) && $expiration < time();
    }
}
