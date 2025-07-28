<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Operation\Factory\TagNormalizer;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations for ArrayDriver.
 *
 * This class follows the established architectural pattern by separating
 * tag operations from the main driver class, maintaining consistency with
 * other drivers like RedisTagOperations and MemcachedTagOperations.
 *
 * Key improvements:
 * - Follows SRP by focusing solely on tag operations
 * - Uses TagValidationTrait for consistent validation
 * - Maintains reference-based access to storage for performance
 * - Implements proper Tag object handling
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       TagValidationTrait for validation logic
 * @see       TagNormalizer for tag normalization utilities
 */
final class ArrayTagOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use ExpirationManagementTrait;

    /**
     * @param array<string, mixed> $storage Reference to main storage array
     * @param array<string, int> $expirations Reference to expiration tracking
     * @param array<string, list<string>> $tagIndex Reference to tag index
     * @param callable $removeFromIndexesCallback Callback to remove keys from all indexes
     */
    public function __construct(
        private array &$storage,
        private array &$expirations,
        private array &$tagIndex,
        private $removeFromIndexesCallback
    ) {
    }

    /**
     * Sets a cache item with associated tags.
     *
     * This method follows the established pattern of validating inputs,
     * storing the value, and then updating tag indices. It maintains
     * consistency with other driver implementations.
     *
     * @param string $key The cache key to set
     * @param mixed $value The value to store
     * @param array<string|Tag> $tags Array of tags (strings or Tag objects)
     * @param \DateInterval|int|null $ttl Time to live for the cache item
     *
     * @return bool True if the item was successfully stored, false otherwise
     *
     * @example
     * ```php
     * $success = $tagOps->setWithTags('user:123', $userData, ['user', 'profile'], 3600);
     * $success = $tagOps->setWithTags('post:456', $post, [new Tag('post'), new Tag('blog')]);
     * ```
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // Validate and normalize tags using shared trait
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        // Calculate expiration time
        $expiration = $this->calculateExpiration($ttl);

        // Store the value
        $this->storage[$key] = $value;

        // Set expiration if provided
        if ($expiration) {
            $this->expirations[$key] = $expiration->getTimestamp();
        } else {
            unset($this->expirations[$key]);
        }

        // Update tag indices
        foreach ($normalizedTags as $tag) {
            $tagString = (string) $tag;
            if (!isset($this->tagIndex[$tagString])) {
                $this->tagIndex[$tagString] = [];
            }

            // Avoid duplicates in tag index
            if (!in_array($key, $this->tagIndex[$tagString], true)) {
                $this->tagIndex[$tagString][] = $key;
            }
        }

        return true;
    }

    /**
     * Invalidates all cache items associated with the given tags.
     *
     * This method efficiently collects all keys associated with the specified
     * tags and removes them from both storage and indices, maintaining
     * data consistency.
     *
     * @param array<string|Tag> $tags Array of tags to invalidate
     *
     * @return bool True if tags were successfully invalidated, false otherwise
     *
     * @example
     * ```php
     * $success = $tagOps->invalidateTags(['user', 'profile']);
     * $success = $tagOps->invalidateTags([new Tag('post'), new Tag('blog')]);
     * ```
     */
    public function invalidateTags(array $tags): bool
    {
        // Validate and normalize tags
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        $keysToDelete = [];

        // Collect all keys associated with the tags
        foreach ($normalizedTags as $tag) {
            $tagString = (string) $tag;
            if (isset($this->tagIndex[$tagString])) {
                $keysToDelete = array_merge($keysToDelete, $this->tagIndex[$tagString]);
                // Remove the tag index completely
                unset($this->tagIndex[$tagString]);
            }
        }

        // Remove duplicates
        $keysToDelete = array_unique($keysToDelete);

        // Delete all associated keys
        foreach ($keysToDelete as $key) {
            unset($this->storage[$key], $this->expirations[$key]);
            // Remove from other indices (topics, etc.)
            ($this->removeFromIndexesCallback)($key);
        }

        return true;
    }

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * This method efficiently collects keys from multiple tags and returns
     * their values, filtering out expired entries in the process.
     *
     * @param array<string|Tag> $tags Array of tags to search for
     *
     * @return array<string, mixed> Array of cache items indexed by their keys
     *
     * @example
     * ```php
     * $userItems = $tagOps->getByTags(['user']);
     * $blogPosts = $tagOps->getByTags([new Tag('post'), new Tag('blog')]);
     * ```
     */
    public function getByTags(array $tags): array
    {
        // Validate and normalize tags
        $normalizedTags = $this->validateAndNormalizeTags($tags);

        $keys = [];

        // Collect all keys associated with the tags
        foreach ($normalizedTags as $tag) {
            $tagString = (string) $tag;
            if (isset($this->tagIndex[$tagString])) {
                $keys = array_merge($keys, $this->tagIndex[$tagString]);
            }
        }

        // Remove duplicates
        $uniqueKeys = array_unique($keys);

        $results = [];

        // Retrieve values for all keys, checking expiration
        foreach ($uniqueKeys as $key) {
            if (isset($this->storage[$key])) {
                // Check if expired
                if (isset($this->expirations[$key]) && $this->expirations[$key] < time()) {
                    // Clean up expired entry
                    unset($this->storage[$key], $this->expirations[$key]);
                    ($this->removeFromIndexesCallback)($key);
                    continue;
                }

                $results[$key] = $this->storage[$key];
            }
        }

        return $results;
    }

    /**
     * Gets all tags currently in use.
     *
     * This method returns all tag strings that currently have associated
     * cache items, providing visibility into the tag ecosystem.
     *
     * @return array<string> Array of unique tag strings currently in use
     */
    public function getTags(): array
    {
        return array_keys($this->tagIndex);
    }

    /**
     * Gets all keys associated with a specific tag.
     *
     * This method is useful for debugging and administrative purposes,
     * allowing inspection of tag-to-key relationships.
     *
     * @param Tag $tag The tag to search for
     *
     * @return array<string> Array of keys associated with the tag
     */
    public function getTaggedKeys(Tag $tag): array
    {
        $tagString = (string) $tag;

        return $this->tagIndex[$tagString] ?? [];
    }

    /**
     * Removes a key from the tag index when the key is deleted.
     *
     * This method is called internally when keys are removed to maintain
     * index consistency and prevent memory leaks.
     *
     * @param string $key The key to remove from tag indices
     */
    public function removeKeyFromTagIndex(string $key): void
    {
        foreach ($this->tagIndex as $tag => &$keys) {
            if (($index = array_search($key, $keys, true)) !== false) {
                unset($keys[$index]);
                // Reindex array to maintain sequential indices
                $keys = array_values($keys);

                // Remove empty tag indices
                if (empty($keys)) {
                    unset($this->tagIndex[$tag]);
                }
            }
        }
    }

    /**
     * Gets statistics about tag usage.
     *
     * @return array{total_tags: int, total_tagged_keys: int, average_keys_per_tag: float}
     */
    public function getTagStatistics(): array
    {
        $totalTags = count($this->tagIndex);
        $totalTaggedKeys = 0;

        foreach ($this->tagIndex as $keys) {
            $totalTaggedKeys += count($keys);
        }

        $averageKeysPerTag = $totalTags > 0 ? $totalTaggedKeys / $totalTags : 0;

        return [
            'total_tags' => $totalTags,
            'total_tagged_keys' => $totalTaggedKeys,
            'average_keys_per_tag' => round($averageKeysPerTag, 2),
        ];
    }
}
