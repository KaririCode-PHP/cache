<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Log\LoggerInterface;

/**
 * Handles tag-based cache operations for FilesystemDriver.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class FilesystemTagOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;

    private const TAG_INDEX_DIRECTORY = 'tags';
    private const TAG_INDEX_FILE_EXTENSION = '.tagindex';
    private const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private readonly FileManager $fileManager,
        private readonly DirectoryManager $directoryManager,
        private readonly CacheSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly ExpirationProvider $expirationProvider,
        private readonly string $keyPrefix = 'cache_'
    ) {
        $this->initializeTagIndexDirectory();
    }

    /**
     * @param array<Tag> $tags
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateTags($tags);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        try {
            return $this->performSetWithTags($key, $value, $tags, $finalExpiration);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set cache item with tags', [
                'key' => $key,
                'tags' => array_map(fn (Tag $tag) => (string) $tag, $tags),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<Tag> $tags
     *
     * @return array<string>
     */
    public function getByTags(array $tags): array
    {
        $this->validateTags($tags);

        try {
            return $this->performGetByTags($tags);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get cache items by tags', [
                'tags' => array_map(fn (Tag $tag) => (string) $tag, $tags),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array<Tag> $tags
     */
    public function deleteByTags(array $tags): bool
    {
        $this->validateTags($tags);

        try {
            return $this->performDeleteByTags($tags);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete cache items by tags', [
                'tags' => array_map(fn (Tag $tag) => (string) $tag, $tags),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<Tag> $tags
     */
    private function performSetWithTags(string $key, mixed $value, array $tags, ?\DateTimeInterface $expiration): bool
    {
        $filename = $this->generateFilename($key);

        $metadata = $this->createMetadata($value, $expiration, $tags);
        $serializedData = $this->serializer->serialize($metadata);

        if (!$this->fileManager->writeSecurely($filename, $serializedData)) {
            return false;
        }

        // Update tag indexes
        foreach ($tags as $tag) {
            $this->updateTagIndex($tag, $key, true);
        }

        return true;
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
            $keys = $this->getKeysForTag($tag);
            if (empty($keys)) {
                return []; // If any tag has no keys, intersection will be empty
            }
            $keySets[] = $keys;
        }

        // Find intersection of all tag key sets
        $intersectionKeys = array_intersect(...$keySets);

        // Filter out expired items
        $validKeys = [];
        foreach ($intersectionKeys as $key) {
            if ($this->isKeyValid($key)) {
                $validKeys[] = $key;
            }
        }

        return $validKeys;
    }

    /**
     * @param array<Tag> $tags
     */
    private function performDeleteByTags(array $tags): bool
    {
        $keys = $this->performGetByTags($tags);

        if (empty($keys)) {
            return true;
        }

        $allSuccessful = true;

        foreach ($keys as $key) {
            $filename = $this->generateFilename($key);

            if (!$this->fileManager->delete($filename)) {
                $allSuccessful = false;
                continue;
            }

            // Remove from tag indexes
            foreach ($tags as $tag) {
                $this->updateTagIndex($tag, $key, false);
            }
        }

        return $allSuccessful;
    }

    /**
     * @param array<Tag> $tags
     *
     * @return array{value: mixed, expiration: int|null, created: int, accessed: int, size: int, tags: array<string>}
     */
    private function createMetadata(mixed $value, ?\DateTimeInterface $expiration, array $tags): array
    {
        return [
            'value' => $value,
            'expiration' => $expiration?->getTimestamp(),
            'created' => time(),
            'accessed' => time(),
            'size' => 0, // Will be set after serialization
            'tags' => array_map(fn (Tag $tag) => (string) $tag, $tags),
        ];
    }

    private function updateTagIndex(Tag $tag, string $key, bool $add): void
    {
        $tagIndexFile = $this->getTagIndexFilename($tag);
        $keys = $this->loadTagIndex($tagIndexFile);

        if ($add) {
            $keys[$key] = time(); // Store timestamp for potential cleanup
        } else {
            unset($keys[$key]);
        }

        $this->saveTagIndex($tagIndexFile, $keys);
    }

    /**
     * @return array<string>
     */
    private function getKeysForTag(Tag $tag): array
    {
        $tagIndexFile = $this->getTagIndexFilename($tag);
        $keys = $this->loadTagIndex($tagIndexFile);

        return array_keys($keys);
    }

    /**
     * @return array<string, int>
     */
    private function loadTagIndex(string $filename): array
    {
        if (!$this->fileManager->exists($filename)) {
            return [];
        }

        try {
            $content = $this->fileManager->read($filename);
            $data = $this->serializer->unserialize($content);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, int> $keys
     */
    private function saveTagIndex(string $filename, array $keys): void
    {
        try {
            $serialized = $this->serializer->serialize($keys);
            $this->fileManager->writeSecurely($filename, $serialized);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to save tag index', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isKeyValid(string $key): bool
    {
        $filename = $this->generateFilename($key);

        if (!$this->fileManager->exists($filename)) {
            return false;
        }

        try {
            $content = $this->fileManager->read($filename);
            $metadata = $this->serializer->unserialize($content);

            if (!is_array($metadata) || !isset($metadata['expiration'])) {
                return true; // No expiration means it's valid
            }

            return null === $metadata['expiration'] || $metadata['expiration'] > time();
        } catch (\Throwable) {
            return false;
        }
    }

    private function getTagIndexFilename(Tag $tag): string
    {
        $tagHash = hash(self::HASH_ALGORITHM, (string) $tag);

        return $this->directoryManager->getBasePath() . DIRECTORY_SEPARATOR .
               self::TAG_INDEX_DIRECTORY . DIRECTORY_SEPARATOR .
               $tagHash . self::TAG_INDEX_FILE_EXTENSION;
    }

    private function generateFilename(string $key): string
    {
        $hash = hash(self::HASH_ALGORITHM, $key);
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $safeKey = substr($safeKey, 0, 50); // Limit length

        $filename = $this->keyPrefix . $safeKey . '_' . substr($hash, 0, 8) . '.cache';
        $directory = $this->directoryManager->createSubdirectoryPath($hash, 2);

        return $directory . $filename;
    }

    private function initializeTagIndexDirectory(): void
    {
        $tagIndexPath = $this->directoryManager->getBasePath() . DIRECTORY_SEPARATOR . self::TAG_INDEX_DIRECTORY;
        $this->directoryManager->createDirectory($tagIndexPath);
    }
}
