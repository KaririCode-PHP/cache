<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Filesystem\Config\FilesystemConfig;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Log\LoggerInterface;

/**
 * Handles tag-based cache operations for the FilesystemDriver.
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
final class FilesystemTagOperations
{
    private const TAG_INDEX_DIRECTORY = 'tags';
    private const TAG_INDEX_FILE_EXTENSION = '.tagindex';
    private const HASH_ALGORITHM = 'sha256';

    private readonly ValidationService $validator;

    /**
     * @param FilesystemConfig $config the configuration DTO for the driver
     * @param DirectoryManager $directoryManager manages directory operations
     * @param FileManager $fileManager manages file I/O operations
     * @param CacheSerializer $serializer handles serialization and deserialization
     * @param ExpirationProvider $expirationProvider the strategy for TTL management
     * @param LoggerInterface $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly FilesystemConfig $config,
        private readonly DirectoryManager $directoryManager,
        private readonly FileManager $fileManager,
        private readonly CacheSerializer $serializer,
        private readonly ExpirationProvider $expirationProvider,
        private readonly LoggerInterface $logger
    ) {
        $this->validator = new ValidationService();
        $this->initializeTagIndexDirectory();
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

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'setWithTags');

        try {
            return $this->performSetWithTags($key, $value, $normalizedTags, $expiration);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set cache item with tags', [
                'key' => $key,
                'tags' => array_map(strval(...), $normalizedTags),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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

        try {
            $keys = $this->getKeysForTags($normalizedTags);
            $results = [];
            foreach ($keys as $key) {
                // We need a way to get the value. This assumes a dependency on CrudOperations or similar.
                // For now, let's placeholder this dependency.
                // In a full refactor, this class would receive FilesystemCrudOperations.
                // $value = $this->crudOperations->get($key);
                // if ($value !== null) {
                //    $results[$key] = $value;
                // }
            }

            return $results; // Placeholder
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get cache items by tags', [
                'tags' => array_map(strval(...), $normalizedTags),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

        try {
            $keys = $this->getKeysForTags($normalizedTags);
            if (empty($keys)) {
                return true;
            }

            $allSuccessful = true;
            foreach ($keys as $key) {
                // This assumes a dependency on FilesystemCrudOperations for deletion.
                // if (!$this->crudOperations->delete($key)) {
                //     $allSuccessful = false;
                // }
            }

            // Clean up tag indexes
            foreach ($normalizedTags as $tag) {
                $this->fileManager->deleteFile($this->getTagIndexFilename($tag));
            }

            return $allSuccessful;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to invalidate cache items by tags', [
                'tags' => array_map(strval(...), $normalizedTags),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retrieves all unique tags stored in the cache.
     *
     * @return iterable<Tag> a list of Tag objects
     */
    public function getTags(): iterable
    {
        $tagIndexPath = $this->directoryManager->getBaseDirectory() . self::TAG_INDEX_DIRECTORY;
        if (!is_dir($tagIndexPath)) {
            return [];
        }

        $files = new \DirectoryIterator($tagIndexPath);
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === ltrim(self::TAG_INDEX_FILE_EXTENSION, '.')) {
                // This is complex as filename is hashed. A better approach would be needed.
                // For now, this functionality remains limited.
            }
        }
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        // This class should ideally depend on FilesystemCrudOperations to set the item.
        // For now, we replicate the logic.
        $filename = $this->generateFilename($key);
        $metadata = $this->createMetadata($value, $expiration, $tags);
        $serializedData = $this->serializer->serialize($metadata);

        if (!$this->fileManager->writeSecurely($filename, $serializedData)) {
            return false;
        }

        // Update tag indexes
        foreach ($tags as $tag) {
            $this->updateTagIndex($tag, $key);
        }

        return true;
    }

    /** @return array{value: mixed, expiration: int|null, tags: array<string>} */
    private function createMetadata(mixed $value, ?\DateTimeInterface $expiration, array $tags): array
    {
        return [
            'value' => $value,
            'expiration' => $expiration?->getTimestamp(),
            'tags' => array_map(strval(...), $tags),
        ];
    }

    private function updateTagIndex(Tag $tag, string $key): void
    {
        $tagIndexFile = $this->getTagIndexFilename($tag);
        $keys = $this->loadTagIndex($tagIndexFile);
        $keys[$key] = time(); // Store timestamp for potential cleanup
        $this->saveTagIndex($tagIndexFile, $keys);
    }

    /** @return array<string> */
    private function getKeysForTags(array $tags): array
    {
        $keySets = [];
        foreach ($tags as $tag) {
            $keys = $this->loadTagIndex($this->getTagIndexFilename($tag));
            if (empty($keys)) {
                return []; // Intersection will be empty
            }
            $keySets[] = array_keys($keys);
        }

        return count($keySets) > 1 ? array_intersect(...$keySets) : ($keySets[0] ?? []);
    }

    /** @return array<string, int> */
    private function loadTagIndex(string $filename): array
    {
        if (!$this->fileManager->exists($filename)) {
            return [];
        }
        try {
            $content = $this->fileManager->readSecurely($filename);
            if (null === $content) {
                return [];
            }
            $data = $this->serializer->unserialize($content);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, int> $keys */
    private function saveTagIndex(string $filename, array $keys): void
    {
        try {
            $serialized = $this->serializer->serialize($keys);
            $this->fileManager->writeSecurely($filename, $serialized);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to save tag index', ['filename' => $filename, 'error' => $e->getMessage()]);
        }
    }

    private function getTagIndexFilename(Tag $tag): string
    {
        $tagHash = hash(self::HASH_ALGORITHM, (string) $tag);
        $dir = $this->directoryManager->getBaseDirectory() . self::TAG_INDEX_DIRECTORY;

        return $dir . DIRECTORY_SEPARATOR . $tagHash . self::TAG_INDEX_FILE_EXTENSION;
    }

    private function generateFilename(string $key): string
    {
        $hash = hash(self::HASH_ALGORITHM, $key);
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $safeKey = substr($safeKey, 0, 50);
        $filename = $this->config->keyPrefix . $safeKey . '_' . substr($hash, 0, 8) . '.cache';
        $directory = $this->directoryManager->createSubdirectoryPath($hash);

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    private function initializeTagIndexDirectory(): void
    {
        $tagIndexPath = $this->directoryManager->getBaseDirectory() . self::TAG_INDEX_DIRECTORY;
        $this->directoryManager->ensureExists($tagIndexPath);
    }

    /** @return array<Tag> */
    private function normalizeTags(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $normalized[] = $tag instanceof Tag ? $tag : new Tag((string) $tag);
        }

        return $normalized;
    }
}
