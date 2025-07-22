<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\ValueObject\Tag;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles cache operations related to tagging.
 * Follows SRP by focusing only on tag-related operations.
 */
final class TaggedCacheOperations
{
    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Persists an item in the cache, associating it with one or more tags.
     *
     * @param string $key the cache item key
     * @param mixed $value the value to store
     * @param Tag[] $tags an array of Tag objects
     * @param \DateInterval|int|null $ttl the Time To Live for the item
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $startTime = microtime(true);
        try {
            $item = $this->layeredCache->getItem($key);
            $item->set($value);

            if (null !== $ttl) {
                $item->expiresAfter($ttl);
            }

            if ($item instanceof CacheItem) {
                $item->tag($tags);
            }

            $result = $this->layeredCache->save($item);

            if ($result && $this->eventDispatcher) {
                $duration = microtime(true) - $startTime;
                $tagStrings = array_map(fn (Tag $tag) => (string) $tag, $tags);
                $this->eventDispatcher->dispatch(new CacheSetEvent($key, $value, $ttl, $tagStrings, $duration));
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache setWithTags operation failed', [
                'key' => $key,
                'tags' => array_map(fn (Tag $tag) => (string) $tag, $tags),
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('setWithTags', $key, $e);
        }
    }

    /**
     * Invalidates all cache items associated with the specified tags.
     *
     * @param Tag[] $tags the tags to invalidate
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success, false on failure
     */
    public function invalidateTags(array $tags): bool
    {
        try {
            $tagStrings = array_map(fn (Tag $tag) => (string) $tag, $tags);
            $keysToInvalidate = $this->getByTags($tagStrings);
            $affectedKeys = count($keysToInvalidate);

            $result = $this->layeredCache->invalidateTags($tags);

            if ($result && $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new TagInvalidationEvent($tagStrings, $affectedKeys));
            }

            return $result;
        } catch (\Throwable $e) {
            $tagStrings = array_map(fn (Tag $tag) => (string) $tag, $tags);
            $this->logger->error('Tag invalidation failed', [
                'tags' => $tagStrings,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::tagInvalidationFailed($tagStrings, $e);
        }
    }

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * @param string[] $tags an array of tag strings
     *
     * @return array<string, mixed> an associative array of key => value pairs
     */
    public function getByTags(array $tags): array
    {
        return $this->layeredCache->getByTags($tags);
    }

    /**
     * Gets all tags currently in use across all cache layers.
     *
     * @return string[] an array of unique tag strings
     */
    public function getAllTags(): array
    {
        return $this->layeredCache->getTags();
    }
}
