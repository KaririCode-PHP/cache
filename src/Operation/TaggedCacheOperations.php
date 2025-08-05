<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Operation\Factory\TagNormalizer;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced TaggedCacheOperations with proper TTL/expiration handling.
 *
 * This class handles cache operations related to tagging while maintaining
 * consistent TTL/expiration behavior across all operations. It follows
 * Clean Architecture principles and SOLID design patterns.
 *
 * Key improvements:
 * - Consistent TTL/expiration handling using ExpirationProvider
 * - Support for DateInterval, int, and null TTL values
 * - Proper tag validation and normalization
 * - Enhanced error handling and logging
 * - PSR-14 event dispatching support
 * - Type safety with strict typing
 * - Comprehensive documentation
 *
 * Design Patterns Applied:
 * - Strategy Pattern: ExpirationProvider for TTL management
 * - Template Method: Consistent operation flow
 * - Factory Pattern: TagNormalizer for tag creation
 * - Observer Pattern: Event dispatching for monitoring
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       TagNormalizer for tag normalization utilities
 * @see       ExpirationProvider for TTL management strategy
 */
final class TaggedCacheOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TtlValidationTrait;

    private readonly LoggerInterface $logger;

    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param LayeredCacheContract $layeredCache The underlying cache implementation
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 event dispatcher
     */
    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        private readonly ExpirationProvider $expirationProvider,
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    // ========================================================================
    // Tag-based Cache Operations with Enhanced TTL Support
    // ========================================================================

    /**
     * Sets a cache item with associated tags and proper TTL handling.
     *
     * This method provides enhanced functionality by:
     * - Supporting flexible TTL formats (DateInterval, int, null)
     * - Using ExpirationProvider for consistent TTL management
     * - Normalizing tags for consistency
     * - Providing comprehensive error handling and logging
     * - Dispatching events for monitoring
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param iterable<string|Tag> $tags Tags to associate with the cache item
     * @param \DateInterval|int|null $ttl Time-to-live (supports DateInterval, seconds as int, or null for default)
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When parameters are invalid
     *
     * @return bool True on success, false on failure
     *
     * @example
     * ```php
     * // Using integer TTL (seconds)
     * $success = $taggedOps->setWithTags('user:123', $userData, ['user', 'profile'], 3600);
     *
     * // Using DateInterval TTL
     * $success = $taggedOps->setWithTags('post:456', $postData, [new Tag('post')], new DateInterval('PT1H'));
     *
     * // Using default TTL from ExpirationProvider
     * $success = $taggedOps->setWithTags('cache:key', $data, ['general'], null);
     * ```
     */
    public function setWithTags(string $key, mixed $value, iterable $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTtl($ttl);

        $startTime = microtime(true);

        try {
            $normalizedTags = $this->normalizeTags($tags);
            $expiration = $this->resolveExpiration($key, $ttl, 'setWithTags');

            $success = $this->layeredCache->setWithTags($key, $value, $normalizedTags, $expiration);
            $operationTime = microtime(true) - $startTime;

            if ($success) {
                $this->dispatchSetWithTagsEvent($key, $value, $normalizedTags, $ttl, $operationTime);

                $this->logger->debug('Tagged cache set successful', [
                    'key' => $key,
                    'tags' => $this->formatTagsForLogging($normalizedTags),
                    'ttl' => $this->formatTtlForLogging($ttl),
                    'expiration' => $expiration?->format('c'),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            } else {
                $this->logger->warning('Tagged cache set failed', [
                    'key' => $key,
                    'tags' => $this->formatTagsForLogging($normalizedTags),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            }

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Tagged cache set operation failed', [
                'key' => $key,
                'tags' => $this->formatTagsForLogging($this->normalizeTags($tags)),
                'ttl' => $this->formatTtlForLogging($ttl),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw CacheException::operationFailed('setWithTags', $key, $exception);
        }
    }

    /**
     * Invalidates all cache items associated with the given tags.
     *
     * This method provides comprehensive tag invalidation with:
     * - Support for both string and Tag object inputs
     * - Proper validation and normalization
     * - Event dispatching for monitoring
     * - Detailed logging for debugging
     *
     * @param iterable<string|Tag> $tags Tags to invalidate (strings or Tag objects)
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When tags are invalid
     *
     * @return bool True on success, false on failure
     *
     * @example
     * ```php
     * // Invalidate using string tags
     * $success = $taggedOps->invalidateTags(['user', 'profile']);
     *
     * // Invalidate using Tag objects
     * $success = $taggedOps->invalidateTags([new Tag('post'), new Tag('blog')]);
     *
     * // Mixed usage
     * $success = $taggedOps->invalidateTags(['user', new Tag('profile')]);
     * ```
     */
    public function invalidateTags(iterable $tags): bool
    {
        $startTime = microtime(true);

        try {
            $normalizedTags = $this->normalizeTags($tags);

            if (empty($normalizedTags)) {
                $this->logger->debug('Tag invalidation skipped - no tags provided');

                return true;
            }

            $success = $this->layeredCache->invalidateTags($normalizedTags);
            $operationTime = microtime(true) - $startTime;

            if ($success) {
                $this->dispatchTagInvalidationEvent($normalizedTags, $operationTime);

                $this->logger->info('Tag invalidation successful', [
                    'tags' => $this->formatTagsForLogging($normalizedTags),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            } else {
                $this->logger->warning('Tag invalidation failed', [
                    'tags' => $this->formatTagsForLogging($normalizedTags),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            }

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Tag invalidation operation failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw CacheException::tagInvalidationFailed($this->formatTagsForLogging($this->normalizeTags($tags)), $exception);
        }
    }

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * This method provides efficient tag-based retrieval with:
     * - Support for both string and Tag object inputs
     * - Proper validation and normalization
     * - Comprehensive error handling
     * - Performance monitoring
     *
     * @param iterable<string|Tag> $tags Tags to search for (strings or Tag objects)
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When tags are invalid
     *
     * @return iterable<string, mixed> Cache items indexed by their keys
     *
     * @example
     * ```php
     * // Get items by string tags
     * $userItems = $taggedOps->getByTags(['user']);
     *
     * // Get items by Tag objects
     * $blogPosts = $taggedOps->getByTags([new Tag('post'), new Tag('blog')]);
     *
     * // Mixed usage
     * $items = $taggedOps->getByTags(['user', new Tag('active')]);
     * ```
     */
    public function getByTags(iterable $tags): iterable
    {
        $startTime = microtime(true);

        try {
            $normalizedTags = $this->normalizeTags($tags);

            if (empty($normalizedTags)) {
                $this->logger->debug('Get by tags skipped - no tags provided');

                return [];
            }

            $results = $this->layeredCache->getByTags($normalizedTags);
            $operationTime = microtime(true) - $startTime;

            $resultCount = is_countable($results) ? count($results) : 0;

            $this->logger->debug('Get by tags completed', [
                'tags' => $this->formatTagsForLogging($normalizedTags),
                'result_count' => $resultCount,
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $results;
        } catch (\Throwable $exception) {
            $this->logger->error('Get by tags operation failed', [
                'tags' => $this->formatTagsForLogging($this->normalizeTags($tags)),
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('getByTags', 'tags', $exception);
        }
    }

    /**
     * Returns all tags currently in use in the cache.
     *
     * This method provides comprehensive tag listing with:
     * - Proper error handling
     * - Performance monitoring
     * - Consistent return type (Tag objects)
     *
     * @throws CacheException When cache operation fails
     *
     * @return iterable<Tag> Collection of Tag objects currently in use
     *
     * @example
     * ```php
     * $allTags = $taggedOps->getTags();
     * foreach ($allTags as $tag) {
     *     echo "Tag: " . (string) $tag . PHP_EOL;
     * }
     * ```
     */
    public function getTags(): iterable
    {
        $startTime = microtime(true);

        try {
            $tags = $this->layeredCache->getTags();
            $operationTime = microtime(true) - $startTime;

            $tagCount = is_countable($tags) ? count($tags) : 0;

            $this->logger->debug('Get all tags completed', [
                'tag_count' => $tagCount,
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $tags;
        } catch (\Throwable $exception) {
            $this->logger->error('Get tags operation failed', [
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('getTags', 'all', $exception);
        }
    }

    /**
     * Sets multiple cache items with associated tags and proper TTL handling.
     *
     * This method provides batch operation capabilities with:
     * - Consistent TTL handling across all items
     * - Tag normalization and validation
     * - Comprehensive error handling
     * - Performance optimization for batch operations
     *
     * @param iterable<string, mixed> $values Key-value pairs to store
     * @param iterable<string|Tag> $tags Tags to associate with all items
     * @param \DateInterval|int|null $ttl Time-to-live for all items
     *
     * @throws CacheException When cache operation fails
     * @throws InvalidArgumentException When parameters are invalid
     *
     * @return bool True if all items were stored successfully
     *
     * @example
     * ```php
     * $values = [
     *     'user:123' => $userData1,
     *     'user:456' => $userData2,
     * ];
     * $success = $taggedOps->setMultipleWithTags($values, ['user', 'batch'], 3600);
     * ```
     */
    public function setMultipleWithTags(iterable $values, iterable $tags, \DateInterval|int|null $ttl = null): bool
    {
        $values = $this->validateAndNormalizeValues($values);
        $this->validateTtl($ttl);

        if (empty($values)) {
            return true;
        }

        $startTime = microtime(true);

        try {
            $normalizedTags = $this->normalizeTags($tags);
            $expiration = $this->resolveExpiration('batch_operation', $ttl, 'setMultipleWithTags');
            $allSuccess = true;

            foreach ($values as $key => $value) {
                if (!$this->layeredCache->setWithTags($key, $value, $normalizedTags, $expiration)) {
                    $allSuccess = false;
                }
            }

            $operationTime = microtime(true) - $startTime;

            $this->logger->debug('Set multiple with tags completed', [
                'items_count' => count($values),
                'tags' => $this->formatTagsForLogging($normalizedTags),
                'success' => $allSuccess,
                'ttl' => $this->formatTtlForLogging($ttl),
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);

            return $allSuccess;
        } catch (\Throwable $exception) {
            $this->logger->error('Set multiple with tags operation failed', [
                'items_count' => count($values),
                'error' => $exception->getMessage(),
            ]);

            throw CacheException::operationFailed('setMultipleWithTags', 'batch', $exception);
        }
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Normalizes mixed tag input to consistent Tag objects.
     *
     * @param iterable<string|Tag> $tags Mixed tag input
     *
     * @throws InvalidArgumentException When tags are invalid
     *
     * @return array<Tag> Normalized Tag objects
     */
    private function normalizeTags(iterable $tags): array
    {
        try {
            return TagNormalizer::normalize(is_array($tags) ? $tags : iterator_to_array($tags));
        } catch (\Throwable $exception) {
            throw InvalidArgumentException::invalidTag("Failed to normalize tags: {$exception->getMessage()}");
        }
    }

    /**
     * Resolves expiration time using ExpirationProvider strategy.
     *
     * @param string $key The cache key for context
     * @param \DateInterval|int|null $ttl The provided TTL
     * @param string $operation The operation type for context
     *
     * @return \DateTimeInterface|null The resolved expiration time
     */
    private function resolveExpiration(string $key, \DateInterval|int|null $ttl, string $operation): ?\DateTimeInterface
    {
        // Handle explicit null (use provider defaults)
        if (null === $ttl) {
            $providerTtl = $this->expirationProvider->getTtlForOperation($operation);

            return $this->expirationProvider->calculateExpiration($providerTtl);
        }

        // Handle DateInterval
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        // Handle integer (seconds)
        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return null; // No expiration for non-positive values
            }

            return new \DateTimeImmutable("+{$ttl} seconds");
        }

        // Fallback to ExpirationProvider
        $providerTtl = $this->expirationProvider->getTtlForKey($key);

        return $this->expirationProvider->calculateExpiration($providerTtl);
    }

    /**
     * Validates and normalizes an iterable of key-value pairs.
     *
     * @param iterable<string, mixed> $values
     *
     * @throws InvalidArgumentException When values are invalid
     *
     * @return array<string, mixed>
     */
    private function validateAndNormalizeValues(iterable $values): array
    {
        $normalizedValues = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('All keys must be strings');
            }

            $this->validateKey($key);
            $this->validateValue($value);
            $normalizedValues[$key] = $value;
        }

        return $normalizedValues;
    }

    /**
     * Formats tags for logging purposes.
     *
     * @param array<Tag> $tags
     *
     * @return array<string>
     */
    private function formatTagsForLogging(array $tags): array
    {
        return array_map(fn (Tag $tag) => (string) $tag, $tags);
    }

    /**
     * Formats TTL for logging purposes.
     */
    private function formatTtlForLogging(\DateInterval|int|null $ttl): ?string
    {
        if (null === $ttl) {
            return 'default';
        }

        if ($ttl instanceof \DateInterval) {
            return $ttl->format('P%yY%mM%dDT%hH%iM%sS');
        }

        return "{$ttl}s";
    }

    // ========================================================================
    // Event Dispatching Methods
    // ========================================================================

    /**
     * Dispatches set with tags event.
     */
    private function dispatchSetWithTagsEvent(
        string $key,
        mixed $value,
        array $tags,
        \DateInterval|int|null $ttl,
        float $duration
    ): void {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new CacheSetEvent($key, $value, $ttl, $duration, [
            'tags' => $this->formatTagsForLogging($tags),
            'operation' => 'setWithTags',
        ]);

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatches tag invalidation event.
     */
    private function dispatchTagInvalidationEvent(array $tags, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $event = new TagInvalidationEvent($this->formatTagsForLogging($tags), $duration);
        $this->eventDispatcher->dispatch($event);
    }
}
