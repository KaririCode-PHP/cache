<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Manages tag-based cache operations for APCu.
 *
 * This class is solely responsible for emulating tag functionality, including
 * setting items with tags, invalidating by tag, and retrieving by tag. It
 * uses the ExpirationProvider to manage TTLs for both the cache items and
 * the tag indexes, adhering to SRP and the Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuTagOperations
{
    private readonly ValidationService $validator;

    /**
     * @param ApcuConfig $config configuration settings for APCu
     * @param ApcuErrorHandler $errorHandler error handling and retry logic
     * @param ApcuCrudOperations $crudOperations for storing the primary cache item
     * @param ExpirationProvider $expirationProvider strategy for TTL management
     */
    public function __construct(
        private readonly ApcuConfig $config,
        private readonly ApcuErrorHandler $errorHandler,
        private readonly ApcuCrudOperations $crudOperations,
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

        if (empty($normalizedTags)) {
            return true;
        }

        $tagNames = array_map(strval(...), $normalizedTags);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTags($normalizedTags),
            'invalidateTags',
            implode(',', $tagNames)
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
            'getByTags',
            implode(',', array_map(strval(...), $normalizedTags))
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
        $tagKeys = [];
        $prefix = $this->config->getTagIndexPrefix();
        $iterator = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');

        foreach ($iterator as $item) {
            if (is_array($item) && isset($item['key'])) {
                $tagName = substr($item['key'], strlen($prefix));
                yield new Tag($tagName);
            }
        }
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        // First, store the main cache item using the dedicated CRUD handler.
        if (!$this->crudOperations->set($key, $value, $expiration)) {
            return false;
        }

        $fullKey = $this->config->keyPrefix . $key;

        // Then, update tag indexes.
        foreach ($tags as $tag) {
            $tagKey = $this->config->getTagIndexPrefix() . (string) $tag;

            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if (!$success || !is_array($taggedKeys)) {
                $taggedKeys = [];
            }

            if (!in_array($fullKey, $taggedKeys, true)) {
                $taggedKeys[] = $fullKey;

                // Set tag expiration with a buffer to ensure tags outlive the cached item.
                $itemTtl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
                $tagTtl = $itemTtl > 0 ? $itemTtl + 3600 : 86400; // 1 hour buffer or 24h default

                apcu_store($tagKey, $taggedKeys, $tagTtl);
            }
        }

        return true;
    }

    /**
     * @param array<Tag> $tags
     */
    private function performInvalidateTags(array $tags): bool
    {
        $keysToDelete = [];

        // Collect all keys associated with the tags.
        foreach ($tags as $tag) {
            $tagKey = $this->config->getTagIndexPrefix() . (string) $tag;
            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if ($success && is_array($taggedKeys)) {
                $keysToDelete = array_merge($keysToDelete, $taggedKeys);
            }

            // Delete the tag index itself.
            apcu_delete($tagKey);
        }

        $keysToDelete = array_unique($keysToDelete);

        if (empty($keysToDelete)) {
            return true;
        }

        // Delete all tagged items. apcu_delete returns an array of failed keys.
        $failures = apcu_delete($keysToDelete);

        return empty($failures);
    }

    /**
     * @param array<Tag> $tags
     *
     * @return iterable<string, mixed>
     */
    private function performGetByTags(array $tags): iterable
    {
        if (empty($tags)) {
            return [];
        }

        $keySets = [];
        foreach ($tags as $tag) {
            $tagKey = $this->config->getTagIndexPrefix() . (string) $tag;
            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if (!$success || !is_array($taggedKeys)) {
                return []; // If any tag has no keys, the intersection will be empty.
            }
            $keySets[] = $taggedKeys;
        }

        // Find the intersection of all tag key sets.
        $intersectionKeys = count($keySets) > 1 ? array_intersect(...$keySets) : ($keySets[0] ?? []);

        if (empty($intersectionKeys)) {
            return [];
        }

        // Retrieve the values for the matching keys.
        $values = apcu_fetch($intersectionKeys);
        if (!is_array($values)) {
            return [];
        }

        // Return the final key-value map with unserialized values.
        $results = [];
        $prefixLength = strlen($this->config->keyPrefix);
        foreach ($values as $fullKey => $value) {
            $originalKey = substr($fullKey, $prefixLength);
            $results[$originalKey] = $this->unserialize($value);
        }

        return $results;
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
