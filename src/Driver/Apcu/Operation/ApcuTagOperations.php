<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Manages tag-based cache invalidation for APCu.
 * SRP: This class is only responsible for tag operations.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class ApcuTagOperations
{
    use CacheKeyValidationTrait;
    use TagValidationTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler,
        private ApcuCrudOperations $crudOperations,
        private ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * @param array<Tag> $tags
     */
    public function setWithTags(
        string $key,
        mixed $value,
        array $tags,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $this->validateKey($key);
        $this->validateTags($tags);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetWithTags($key, $value, $tags, $finalExpiration),
            'setWithTags',
            $key
        );
    }

    /**
     * @param array<Tag> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);

        if (empty($tags)) {
            return true;
        }

        $tagNames = array_map(fn (Tag $tag) => (string) $tag, $tags);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performInvalidateTags($tags),
            'invalidateTags',
            implode(',', $tagNames)
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
            'getByTags',
            implode(',', array_map(fn (Tag $tag) => (string) $tag, $tags))
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        // First, store the main cache item
        if (!$this->crudOperations->set($key, $value, $expiration)) {
            return false;
        }

        $fullKey = $this->config->keyPrefix . $key;

        // Then, update tag indexes
        foreach ($tags as $tag) {
            $tagKey = $this->config->getTagIndexPrefix() . (string) $tag;

            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if (!$success || !is_array($taggedKeys)) {
                $taggedKeys = [];
            }

            if (!in_array($fullKey, $taggedKeys, true)) {
                $taggedKeys[] = $fullKey;

                // Set tag expiration with buffer to ensure tags outlive the cached item
                $tagTtl = $expiration ?
                    max(0, $expiration->getTimestamp() - time()) + 3600 : // 1 hour buffer
                    3600; // Default 1 hour for tags

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

        // Collect all keys associated with the tags
        foreach ($tags as $tag) {
            $tagKey = $this->config->getTagIndexPrefix() . (string) $tag;

            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if ($success && is_array($taggedKeys)) {
                $keysToDelete = array_merge($keysToDelete, $taggedKeys);
            }

            // Delete the tag index
            apcu_delete($tagKey);
        }

        $keysToDelete = array_unique($keysToDelete);

        if (empty($keysToDelete)) {
            return true;
        }

        // Delete all tagged keys
        $failures = apcu_delete($keysToDelete);

        return !is_array($failures) || empty($failures);
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

        $keySets = [];

        foreach ($tags as $tag) {
            $tagKey = $this->config->getTagIndexPrefix() . (string) $tag;

            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if (!$success || !is_array($taggedKeys)) {
                return []; // If any tag has no keys, intersection will be empty
            }

            $keySets[] = $taggedKeys;
        }

        // Find intersection of all tag key sets
        $intersectionKeys = array_intersect(...$keySets);

        // Remove prefix from keys to return original cache keys
        $prefixLength = strlen($this->config->keyPrefix);

        return array_map(
            fn ($key) => substr($key, $prefixLength),
            $intersectionKeys
        );
    }

    private function validateSingleTag(string $tag): void
    {
        if (empty($tag)) {
            throw InvalidArgumentException::invalidTag('Tag cannot be empty.');
        }
    }
}
