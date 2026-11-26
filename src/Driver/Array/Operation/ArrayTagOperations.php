<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Array\Storage\ArrayStorage;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Handles tag-based cache operations for the ArrayDriver.
 *
 * This class is solely responsible for managing the relationship between cache
 * keys and tags. It uses the central ArrayStorage for data manipulation and
 * the ExpirationProvider strategy for TTL management, adhering to the Single
 * Responsibility Principle.
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
    private readonly ValidationService $validator;

    /**
     * @param ArrayStorage $storage the central in-memory storage
     * @param ExpirationProvider $expirationProvider the strategy for TTL management
     * @param callable $removeCallback a callback to completely remove a key from all indexes
     */
    public function __construct(
        private readonly ArrayStorage $storage,
        private readonly ExpirationProvider $expirationProvider,
        private readonly \Closure $removeCallback
    ) {
        $this->validator = new ValidationService();
    }

    /**
     * Stores an item with associated tags.
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

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'setWithTags');
        $this->storage->setValue($key, $value, $expiration?->getTimestamp());

        foreach ($normalizedTags as $tag) {
            $this->storage->addTagAssociation((string) $tag, $key, $expiration?->getTimestamp());
        }

        return true;
    }

    /**
     * Retrieves all items associated with any of the given tags.
     *
     * @param iterable<string|Tag> $tags the tags to search for
     *
     * @return iterable<string, mixed> a map of keys to their values
     */
    public function getByTags(iterable $tags): iterable
    {
        $normalizedTags = $this->normalizeTags($tags);
        $this->validator->validateTags($normalizedTags);

        $uniqueKeys = [];
        foreach ($normalizedTags as $tag) {
            foreach ($this->storage->getTaggedKeys((string) $tag) as $key) {
                $uniqueKeys[$key] = true;
            }
        }

        $results = [];
        foreach (array_keys($uniqueKeys) as $key) {
            $value = $this->storage->getValue($key);
            if (null !== $value) {
                $results[$key] = $value;
            }
        }

        return $results;
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

        $keysToDelete = [];
        foreach ($normalizedTags as $tag) {
            $tagName = (string) $tag;
            foreach ($this->storage->getTaggedKeys($tagName) as $key) {
                $keysToDelete[$key] = true;
            }
            // Clear the tag index itself
            $this->storage->clearTagAssociations($tagName);
        }

        // Use the callback to ensure keys are removed from all indexes (storage, topics, etc.)
        foreach (array_keys($keysToDelete) as $key) {
            ($this->removeCallback)($key);
        }

        return true;
    }

    /**
     * Retrieves all unique tags stored in the cache.
     *
     * @return iterable<Tag> a list of Tag objects
     */
    public function getTags(): iterable
    {
        $tagNames = $this->storage->getAllTags();
        foreach ($tagNames as $name) {
            yield new Tag($name);
        }
    }

    /**
     * Normalizes an iterable of strings or Tag objects into an array of Tag objects.
     *
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
