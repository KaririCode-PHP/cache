<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Manages tag-based cache invalidation for APCu.
 * SRP: This class is only responsible for tag operations.
 */
final class ApcuTagOperations
{
    use CacheKeyValidationTrait;
    use TagValidationTrait;
    use ExpirationManagementTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler,
        private ApcuCrudOperations $crudOperations
    ) {
    }

    public function setWithTags(
        string $key,
        mixed $value,
        array $tags,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $this->validateKey($key);
        $normalizedTags = $this->validateAndNormalizeTags($tags); // MUDANÇA

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetWithTags($key, $value, $normalizedTags, $expiration),
            'setWithTags',
            $key
        );
    }

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

    public function getTaggedKeys(string $tag): array
    {
        $this->validateSingleTag($tag);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetTaggedKeys($tag),
            'getTaggedKeys',
            $tag
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<string>
     */
    public function getAllTags(): array
    {
        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performGetAllTags(),
            'getAllTags'
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        // To maintain consistency, delegate the SET operation to the appropriate handler.
        if (!$this->crudOperations->set($key, $value, $expiration)) {
            return false;
        }

        $fullKey = $this->config->keyPrefix . $key;

        foreach ($tags as $tag) {
            $tagName = (string) $tag;
            $tagKey = $this->config->getTagIndexPrefix() . $tagName;

            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if (!$success || !is_array($taggedKeys)) {
                $taggedKeys = [];
            }

            if (!in_array($fullKey, $taggedKeys, true)) {
                $taggedKeys[] = $fullKey;
                apcu_store($tagKey, $taggedKeys);
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

        foreach ($tags as $tag) {
            $tagName = (string) $tag;
            $tagKey = $this->config->getTagIndexPrefix() . $tagName;

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

        // Delete the associated cache items
        $failures = apcu_delete($keysToDelete);

        // Clean up references to deleted keys from other tag indices
        $this->cleanupTagIndices($keysToDelete);

        if (!is_array($failures)) {
            return false;
        }

        return empty($failures);
    }

    /**
     * @return array<string>
     */
    private function performGetTaggedKeys(string $tag): array
    {
        $tagKey = $this->config->getTagIndexPrefix() . $tag;

        $success = false;
        $taggedKeys = apcu_fetch($tagKey, $success);

        if (!$success || !is_array($taggedKeys)) {
            return [];
        }

        return array_map(
            fn ($key) => substr($key, strlen($this->config->keyPrefix)),
            $taggedKeys
        );
    }

    /**
     * @return array<string>
     */
    private function performGetAllTags(): array
    {
        $tags = [];
        $pattern = '#^' . preg_quote($this->config->getTagIndexPrefix()) . '#';
        $iterator = new \APCUIterator($pattern);

        foreach ($iterator as $item) {
            if (is_array($item) && isset($item['key'])) {
                $tag = substr(
                    (string) $item['key'],
                    strlen($this->config->getTagIndexPrefix())
                );
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param array<string> $deletedKeys
     */
    private function cleanupTagIndices(array $deletedKeys): void
    {
        $pattern = '#^' . preg_quote($this->config->getTagIndexPrefix()) . '#';
        $iterator = new \APCUIterator($pattern);

        foreach ($iterator as $tagItem) {
            if (!is_array($tagItem) || !isset($tagItem['key'])) {
                continue;
            }

            $tagKey = (string) $tagItem['key'];

            $success = false;
            $taggedKeys = apcu_fetch($tagKey, $success);

            if (!$success || !is_array($taggedKeys)) {
                continue;
            }

            $updatedKeys = array_diff($taggedKeys, $deletedKeys);

            if (empty($updatedKeys)) {
                apcu_delete($tagKey);
            } elseif (count($updatedKeys) < count($taggedKeys)) {
                apcu_store($tagKey, array_values($updatedKeys));
            }
        }
    }

    private function validateSingleTag(string $tag): void
    {
        if (empty($tag)) {
            throw InvalidArgumentException::invalidTag('Tag cannot be empty.');
        }
    }
}
