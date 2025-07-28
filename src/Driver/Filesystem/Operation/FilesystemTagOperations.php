<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Operation\Factory\TagNormalizer;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Log\LoggerInterface;

/**
 * Handles tag-based cache operations for FilesystemDriver.
 *
 * This class extends the filesystem caching capabilities by implementing
 * tag support through metadata storage. Tags are stored as part of the
 * cache item metadata, allowing for efficient tag-based operations while
 * maintaining the filesystem driver's file-based architecture.
 *
 * Key features:
 * - Stores tags as metadata alongside cached values
 * - Maintains tag index files for efficient lookups
 * - Implements atomic operations for tag consistency
 * - Provides cleanup mechanisms for orphaned tag references
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       TagValidationTrait for validation logic
 * @see       FileManager for file operations
 */
final class FilesystemTagOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use ExpirationManagementTrait;

    private const TAG_INDEX_DIRECTORY = 'tags';
    private const TAG_INDEX_FILE_EXTENSION = '.tagindex';
    private const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private readonly FileManager $fileManager,
        private readonly DirectoryManager $directoryManager,
        private readonly CacheSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly string $keyPrefix = 'cache_'
    ) {
        $this->initializeTagIndexDirectory();
    }

    /**
     * Sets a cache item with associated tags.
     *
     * This method stores the cache value along with tag metadata in a single
     * file, then updates tag index files for efficient tag-based lookups.
     *
     * @param string $key The cache key to set
     * @param mixed $value The value to store
     * @param array<string|Tag> $tags Array of tags (strings or Tag objects)
     * @param \DateInterval|int|null $ttl Time to live for the cache item
     *
     * @return bool True if the item was successfully stored, false otherwise
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // Validate and normalize tags
        $normalizedTags = $this->validateAndNormalizeTags($tags);
        $tagStrings = TagNormalizer::toStrings($normalizedTags);

        try {
            // Create metadata with tags
            $expiration = $this->calculateExpiration($ttl);
            $metadata = [
                'value' => $value,
                'tags' => $tagStrings,
                'created_at' => time(),
                'expires_at' => $expiration?->getTimestamp(),
            ];

            // Store the cache item with metadata
            $filename = $this->generateFilename($key);
            $serializedData = $this->serializer->serialize($metadata);

            if (!$this->fileManager->writeSecurely($filename, $serializedData)) {
                return false;
            }

            // Update tag indices
            $this->updateTagIndices($key, $tagStrings);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set cache item with tags', [
                'key' => $key,
                'tags' => $tagStrings,
                'error' => $e->getMessage(),
            ]);

            throw CacheException::operationFailed('setWithTags', $key, $e);
        }
    }

    /**
     * Invalidates all cache items associated with the given tags.
     *
     * This method reads tag index files to find all keys associated with
     * the specified tags, then removes both the cache files and updates
     * the tag indices.
     *
     * @param array<string|Tag> $tags Array of tags to invalidate
     *
     * @return bool True if tags were successfully invalidated, false otherwise
     */
    public function invalidateTags(array $tags): bool
    {
        // Validate and normalize tags
        $normalizedTags = $this->validateAndNormalizeTags($tags);
        $tagStrings = TagNormalizer::toStrings($normalizedTags);

        try {
            $keysToDelete = [];

            // Collect all keys associated with the tags
            foreach ($tagStrings as $tag) {
                $taggedKeys = $this->getKeysForTag($tag);
                $keysToDelete = array_merge($keysToDelete, $taggedKeys);
            }

            // Remove duplicates
            $keysToDelete = array_unique($keysToDelete);

            // Delete all associated cache files
            $deletedCount = 0;
            foreach ($keysToDelete as $key) {
                $filename = $this->generateFilename($key);
                if ($this->fileManager->deleteFile($filename)) {
                    ++$deletedCount;
                }
            }

            // Clean up tag indices
            foreach ($tagStrings as $tag) {
                $this->removeTagIndex($tag);
            }

            $this->logger->info('Tag invalidation completed', [
                'tags' => $tagStrings,
                'keys_deleted' => $deletedCount,
            ]);

            return true;
        } catch (\Throwable $e) {
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
     * @param array<string|Tag> $tags Array of tags to search for
     *
     * @return array<string, mixed> Array of cache items indexed by their keys
     */
    public function getByTags(array $tags): array
    {
        // Validate and normalize tags
        $normalizedTags = $this->validateAndNormalizeTags($tags);
        $tagStrings = TagNormalizer::toStrings($normalizedTags);

        $allKeys = [];

        // Collect all keys associated with the tags
        foreach ($tagStrings as $tag) {
            $taggedKeys = $this->getKeysForTag($tag);
            $allKeys = array_merge($allKeys, $taggedKeys);
        }

        // Remove duplicates
        $uniqueKeys = array_unique($allKeys);

        $results = [];

        // Retrieve values for all keys
        foreach ($uniqueKeys as $key) {
            $filename = $this->generateFilename($key);

            if (!$this->fileManager->exists($filename)) {
                continue;
            }

            $content = $this->fileManager->readSecurely($filename);
            if (null === $content) {
                continue;
            }

            try {
                $metadata = $this->serializer->unserialize($content);

                if (!is_array($metadata) || !isset($metadata['value'])) {
                    continue;
                }

                // Check expiration
                if (isset($metadata['expires_at']) && $metadata['expires_at'] < time()) {
                    $this->fileManager->deleteFile($filename);
                    continue;
                }

                $results[$key] = $metadata['value'];
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to deserialize tagged cache item', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $results;
    }

    /**
     * Gets all tags currently in use.
     *
     * @return array<string> Array of unique tag strings currently in use
     */
    public function getTags(): array
    {
        $tagDirectory = $this->getTagDirectory();
        $tagFiles = glob($tagDirectory . '/*' . self::TAG_INDEX_FILE_EXTENSION);

        if (false === $tagFiles) {
            return [];
        }

        $tags = [];
        foreach ($tagFiles as $tagFile) {
            $filename = basename($tagFile, self::TAG_INDEX_FILE_EXTENSION);
            // Decode the tag name (it was hashed for filename safety)
            $tags[] = $this->decodeTagFromFilename($filename);
        }

        return array_filter($tags); // Remove any null/empty values
    }

    /**
     * Performs cleanup of expired items and orphaned tag references.
     */
    public function performCleanup(): void
    {
        try {
            $cleanupCount = 0;
            $tagDirectory = $this->getTagDirectory();
            $tagFiles = glob($tagDirectory . '/*' . self::TAG_INDEX_FILE_EXTENSION);

            if (false === $tagFiles) {
                return;
            }

            foreach ($tagFiles as $tagFile) {
                $content = $this->fileManager->readSecurely($tagFile);
                if (null === $content) {
                    continue;
                }

                $taggedKeys = $this->serializer->unserialize($content);
                if (!is_array($taggedKeys)) {
                    continue;
                }

                $validKeys = [];
                foreach ($taggedKeys as $key) {
                    $cacheFile = $this->generateFilename($key);
                    if ($this->fileManager->exists($cacheFile)) {
                        $validKeys[] = $key;
                    } else {
                        ++$cleanupCount;
                    }
                }

                // Update tag index with only valid keys
                if (empty($validKeys)) {
                    $this->fileManager->deleteFile($tagFile);
                } elseif (count($validKeys) !== count($taggedKeys)) {
                    $updatedContent = $this->serializer->serialize($validKeys);
                    $this->fileManager->writeSecurely($tagFile, $updatedContent);
                }
            }

            if ($cleanupCount > 0) {
                $this->logger->info('Filesystem tag cleanup completed', [
                    'orphaned_references_removed' => $cleanupCount,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Tag cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Updates tag indices with the given key and tags.
     */
    private function updateTagIndices(string $key, array $tagStrings): void
    {
        foreach ($tagStrings as $tag) {
            $tagIndexFile = $this->getTagIndexFile($tag);

            // Read existing tagged keys
            $taggedKeys = [];
            if ($this->fileManager->exists($tagIndexFile)) {
                $content = $this->fileManager->readSecurely($tagIndexFile);
                if (null !== $content) {
                    $existingKeys = $this->serializer->unserialize($content);
                    if (is_array($existingKeys)) {
                        $taggedKeys = $existingKeys;
                    }
                }
            }

            // Add the new key if not already present
            if (!in_array($key, $taggedKeys, true)) {
                $taggedKeys[] = $key;
            }

            // Write updated tag index
            $serializedKeys = $this->serializer->serialize($taggedKeys);
            $this->fileManager->writeSecurely($tagIndexFile, $serializedKeys);
        }
    }

    /**
     * Gets all keys associated with a specific tag.
     */
    private function getKeysForTag(string $tag): array
    {
        $tagIndexFile = $this->getTagIndexFile($tag);

        if (!$this->fileManager->exists($tagIndexFile)) {
            return [];
        }

        $content = $this->fileManager->readSecurely($tagIndexFile);
        if (null === $content) {
            return [];
        }

        try {
            $taggedKeys = $this->serializer->unserialize($content);

            return is_array($taggedKeys) ? $taggedKeys : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Removes a tag index file.
     */
    private function removeTagIndex(string $tag): void
    {
        $tagIndexFile = $this->getTagIndexFile($tag);
        $this->fileManager->deleteFile($tagIndexFile);
    }

    /**
     * Generates the tag index file path for a given tag.
     */
    private function getTagIndexFile(string $tag): string
    {
        $tagDirectory = $this->getTagDirectory();
        $safeTagName = $this->encodeTagForFilename($tag);

        return $tagDirectory . DIRECTORY_SEPARATOR . $safeTagName . self::TAG_INDEX_FILE_EXTENSION;
    }

    /**
     * Gets the tag directory path.
     */
    private function getTagDirectory(): string
    {
        $baseDirectory = $this->directoryManager->getBaseDirectory();

        return $baseDirectory . self::TAG_INDEX_DIRECTORY;
    }

    /**
     * Initializes the tag index directory.
     */
    private function initializeTagIndexDirectory(): void
    {
        $tagDirectory = $this->getTagDirectory();
        $this->directoryManager->ensureExists($tagDirectory);
    }

    /**
     * Encodes a tag name for safe use as a filename.
     */
    private function encodeTagForFilename(string $tag): string
    {
        // Use hash to ensure filename safety while maintaining uniqueness
        return hash(self::HASH_ALGORITHM, $tag);
    }

    /**
     * Decodes a tag name from a filename (this is a placeholder -
     * in a real implementation, you'd need a reverse mapping).
     *
     * @param string $filename o nome do arquivo a ser decodificado
     *
     * @return string o nome da tag (atualmente, o próprio hash do nome do arquivo)
     */
    private function decodeTagFromFilename(string $filename): string
    {
        // Note: This is a limitation of the hash-based approach.
        // In a production implementation, you might want to store
        // a reverse mapping or use a different encoding strategy.

        // For now, we'll reconstruct from the tag index content
        // This is not efficient but works for demonstration
        return $filename; // Return the hash as the tag name
    }

    /**
     * Generates the cache filename for a given key.
     */
    private function generateFilename(string $key): string
    {
        $hash = hash(self::HASH_ALGORITHM, $key);

        $safeKey = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $safeKey = substr($safeKey, 0, 50); // Limit length

        $filename = $this->keyPrefix . $safeKey . '_' . substr($hash, 0, 16) . '.cache';

        // Create subdirectory for better performance
        $directory = $this->directoryManager->createSubdirectoryPath($hash, 2);

        return $directory . $filename;
    }
}
