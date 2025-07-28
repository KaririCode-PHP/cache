<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Operation\Factory\TagNormalizer;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles cache operations related to tagging.
 *
 * This class follows the Single Responsibility Principle by focusing exclusively
 * on tag-related cache operations. It implements proper validation, error handling,
 * and event dispatching while maintaining consistency with other operation classes
 * in the codebase architecture.
 *
 * Key improvements:
 * - Added TagValidationTrait for proper validation consistency
 * - Enhanced error handling with contextual information
 * - Improved documentation following clean code principles
 * - Maintains the established architectural patterns
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       TagValidationTrait for validation logic
 * @see       CacheException for error handling patterns
 * @see       TagNormalizer for tag normalization utilities
 */
final class TaggedCacheOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TtlValidationTrait;

    /**
     * @param LayeredCacheContract $layeredCache The underlying cache pool for operations
     * @param LoggerInterface $logger PSR-3 compliant logger for operation tracking
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 event dispatcher
     */
    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Sets a cache item with associated tags.
     *
     * This method follows the fail-fast principle by validating all inputs
     * before attempting the cache operation. It supports hybrid tag input
     * (strings or Tag objects) and normalizes them for consistent handling.
     *
     * @param string $key The cache key to set
     * @param mixed $value The value to store
     * @param array<string|Tag> $tags Array of tags (strings or Tag objects)
     * @param \DateInterval|int|null $ttl Time to live for the cache item
     *
     * @throws CacheException If the operation fails
     *
     * @return bool True if the item was successfully stored, false otherwise
     *
     * @example
     * ```php
     * $success = $taggedOps->setWithTags('user:123', $userData, ['user', 'profile'], 3600);
     * $success = $taggedOps->setWithTags('post:456', $post, [new Tag('post'), new Tag('blog')]);
     * ```
     */
    public function setWithTags(
        string $key,
        mixed $value,
        array $tags,
        \DateInterval|int|null $ttl = null
    ): bool {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateMixedTags($tags);
        $this->validateTtl($ttl);

        $startTime = microtime(true);

        try {
            // Validate and normalize tags using the shared validation trait
            $normalizedTags = $this->validateAndNormalizeTags($tags);

            // Get cache item and configure it
            $item = $this->layeredCache->getItem($key);
            $item->set($value);

            if (null !== $ttl) {
                $item->expiresAfter($ttl);
            }

            // Apply tags if the item supports tagging
            if ($item instanceof CacheItem) {
                $item->tag($normalizedTags);
            }

            // Perform the cache operation
            $result = $this->layeredCache->save($item);

            // Dispatch success event if configured
            if ($result && $this->eventDispatcher) {
                $duration = microtime(true) - $startTime;
                $tagStrings = TagNormalizer::toStrings($normalizedTags);
                $this->eventDispatcher->dispatch(
                    new CacheSetEvent($key, $value, $ttl, $tagStrings, $duration)
                );
            }

            return $result;
        } catch (\Throwable $e) {
            // Log detailed error information for debugging
            $this->logger->error('Cache setWithTags operation failed', [
                'key' => $key,
                'tags' => TagNormalizer::toStrings($this->validateAndNormalizeTags($tags)),
                'ttl' => $ttl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw CacheException::operationFailed('setWithTags', $key, $e);
        }
    }

    /**
     * Invalidates all cache items associated with the given tags.
     *
     * This method implements efficient tag-based invalidation by leveraging
     * the underlying cache layer's tag support. It provides comprehensive
     * error handling and event dispatching for monitoring purposes.
     *
     * @param array<string|Tag> $tags Array of tags to invalidate
     *
     * @throws CacheException If the invalidation operation fails
     *
     * @return bool True if tags were successfully invalidated, false otherwise
     *
     * @example
     * ```php
     * $success = $taggedOps->invalidateTags(['user', 'profile']);
     * $success = $taggedOps->invalidateTags([new Tag('post'), new Tag('blog')]);
     * ```
     */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        try {
            // Validate and normalize tags
            $normalizedTags = $this->validateAndNormalizeTags($tags);
            $tagStrings = TagNormalizer::toStrings($normalizedTags);

            // Get affected keys for metrics (before invalidation)
            $keysToInvalidate = $this->getByTags($normalizedTags);
            $affectedKeys = count($keysToInvalidate);

            // Perform the invalidation operation
            $result = $this->layeredCache->invalidateTags($normalizedTags);

            // Dispatch invalidation event if successful
            if ($result && $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(
                    new TagInvalidationEvent($tagStrings, $affectedKeys)
                );
            }

            // Log successful invalidation
            if ($result) {
                $this->logger->info('Tags successfully invalidated', [
                    'tags' => $tagStrings,
                    'affected_keys' => $affectedKeys,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            // Ensure we can always get tag strings for error logging
            $tagStrings = [];
            try {
                $tagStrings = TagNormalizer::toStrings($this->validateAndNormalizeTags($tags));
            } catch (\Throwable) {
                // Fallback to raw tags if normalization fails
                $tagStrings = array_map('strval', $tags);
            }

            $this->logger->error('Tag invalidation failed', [
                'tags' => $tagStrings,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw CacheException::tagInvalidationFailed($tagStrings, $e);
        }
    }

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * This method provides efficient tag-based retrieval by delegating
     * to the underlying cache layer. It maintains consistency with the
     * codebase's delegation pattern and error handling approach.
     *
     * @param array<Tag> $tags Array of Tag objects to search for
     *
     * @return array<string, mixed> Array of cache items indexed by their keys
     *
     * @example
     * ```php
     * $userItems = $taggedOps->getByTags([new Tag('user')]);
     * $blogPosts = $taggedOps->getByTags([new Tag('post'), new Tag('blog')]);
     * ```
     */
    public function getByTags(array $tags): array
    {
        return $this->layeredCache->getByTags($tags);
    }

    public function getAllTags(): array
    {
        return $this->layeredCache->getTags();
    }
}
