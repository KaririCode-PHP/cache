<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileSystemStatistics;
use KaririCode\Cache\Contract\Filesystem\FileSystemValidator;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Driver\Filesystem\Cache\FileSystemCacheOperations;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemTagOperations;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Log\LoggerInterface;

/**
 * Enhanced filesystem-based cache driver with tag support.
 *
 * This driver has been extended to support tag-based cache operations
 * while maintaining the existing filesystem architecture. It demonstrates
 * how the filesystem driver can be enhanced to match the capabilities
 * of other drivers in the system.
 *
 * Key enhancements:
 * - Implements TaggableCacheDriver interface
 * - Delegates tag operations to FilesystemTagOperations
 * - Maintains architectural consistency with other drivers
 * - Preserves existing filesystem optimizations
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       FilesystemTagOperations for tag-specific operations
 * @see       FileSystemCacheOperations for basic cache operations
 */
final class FilesystemDriver implements
    CacheDriver,
    Maintainable,
    TaggableCacheDriver
{
    public function __construct(
        private readonly DirectoryManager $directoryManager,
        private readonly FileSystemCacheOperations $cacheOperations,
        private readonly FilesystemTagOperations $tagOperations,
        private readonly FileSystemStatistics $statistics,
        private readonly FileSystemValidator $validator,
        private readonly LoggerInterface $logger
    ) {
        $this->logger->info('Enhanced FilesystemDriver initialized with tag support');
    }

    // ========================================================================
    // Basic Cache Operations (Existing Implementation)
    // ========================================================================

    public function get(string $key): mixed
    {
        $startTime = microtime(true);
        try {
            $this->validator->validateKey($key);
            $result = $this->cacheOperations->get($key);
            $this->statistics->recordOperation('get', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get cache item', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $startTime = microtime(true);
        try {
            $this->validator->validateKey($key);
            $this->validator->validateValue($value);
            $result = $this->cacheOperations->set($key, $value, $expiration);
            $this->statistics->recordOperation('set', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set cache item', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function has(string $key): bool
    {
        try {
            $this->validator->validateKey($key);

            return $this->cacheOperations->has($key);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check cache item existence', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->validator->validateKey($key);

            return $this->cacheOperations->delete($key);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete cache item', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            return $this->directoryManager->clear();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear cache', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ========================================================================
    // Batch Operations (Existing Implementation)
    // ========================================================================

    public function getMultiple(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $expiration)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    // ========================================================================
    // Tag Operations (NEW - Delegated to FilesystemTagOperations)
    // ========================================================================

    /**
     * Sets a cache item with associated tags.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param array<string|Tag> $tags Array of tags (strings or Tag objects)
     * @param \DateInterval|int|null $ttl Time to live
     *
     * @return bool True on success, false on failure
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        return $this->tagOperations->setWithTags($key, $value, $tags, $ttl);
    }

    /**
     * Invalidates all cache items associated with the given tags.
     *
     * @param array<Tag> $tags Array of Tag objects to invalidate
     *
     * @return bool True if tags were successfully invalidated, false otherwise
     */
    public function invalidateTags(array $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    /**
     * Retrieves all cache items associated with the given tags.
     *
     * @param array<Tag> $tags Array of Tag objects
     *
     * @return array<string, mixed> Array of cache items indexed by their keys
     */
    public function getByTags(array $tags): array
    {
        return $this->tagOperations->getByTags($tags);
    }

    /**
     * Gets all tags currently in use.
     *
     * @return array<string> Array of tag names
     */
    public function getTags(): array
    {
        return $this->tagOperations->getTags();
    }

    // ========================================================================
    // Driver Information and Maintenance
    // ========================================================================

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::TAGGING,
        ];
    }

    public function getStats(): DriverStats
    {
        $statsArray = $this->statistics->getStats();

        return new MetricsSnapshot(
            'Filesystem',
            $statsArray['directory_size'],
            $statsArray['file_count'],
            null, // Filesystem driver does not have a specific version
            $statsArray
        );
    }

    public function isHealthy(): bool
    {
        return $this->directoryManager->isWritable(
            $this->directoryManager->getBaseDirectory()
        );
    }

    public function maintenance(array $options = []): bool
    {
        try {
            // Perform basic cache cleanup
            $this->cacheOperations->performCleanup();

            // Perform tag-specific cleanup
            $this->tagOperations->performCleanup();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Cache maintenance failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
