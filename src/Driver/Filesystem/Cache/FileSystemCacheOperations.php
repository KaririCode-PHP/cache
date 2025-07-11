<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Cache;

use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\ExpirationManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Contract\Filesystem\SerializationManager;
use Psr\Log\LoggerInterface;

/**
 * Cache operations coordinator following SRP.
 * Responsible ONLY for cache-specific business logic.
 */
final class FileSystemCacheOperations
{
    private const HASH_ALGORITHM = 'sha256';
    private const FILENAME_HASH_LENGTH = 16;
    private const MAX_KEY_LENGTH_IN_FILENAME = 50;

    public function __construct(
        private FileManager $fileManager,
        private DirectoryManager $directoryManager,
        private SerializationManager $serializer,
        private ExpirationManager $expirationManager,
        private string $keyPrefix = 'cache_',
        private int $directoryLevels = 2,
        private LoggerInterface $logger
    ) {
    }

    public function get(string $key): mixed
    {
        $filename = $this->generateFilename($key);

        if (!$this->fileManager->exists($filename)) {
            return null;
        }

        $content = $this->fileManager->readSecurely($filename);
        if (null === $content) {
            return null;
        }

        try {
            $metadata = $this->serializer->unserialize($content);

            if (!is_array($metadata) || !isset($metadata['value'])) {
                $this->logger->warning('Invalid cache metadata', ['key' => $key]);

                return null;
            }

            if ($this->expirationManager->isExpired($metadata)) {
                $this->fileManager->deleteFile($filename);

                return null;
            }

            // Update access time
            $this->expirationManager->updateAccessTime($metadata);
            $this->updateMetadataFile($filename, $metadata);

            return $metadata['value'];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to deserialize cache data', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $filename = $this->generateFilename($key);
        $metadata = $this->expirationManager->createMetadata($value, $expiration);

        try {
            $serializedData = $this->serializer->serialize($metadata);
            $metadata['size'] = strlen($serializedData);

            // Update metadata with size
            $serializedData = $this->serializer->serialize($metadata);

            $success = $this->fileManager->writeSecurely($filename, $serializedData);

            if ($success) {
                $this->logger->debug('Cache item stored', [
                    'key' => $key,
                    'size' => $metadata['size'],
                    'expiration' => $expiration?->format('Y-m-d H:i:s'),
                ]);

                // Probabilistic cleanup
                if ($this->expirationManager->shouldCleanup()) {
                    $this->performCleanup();
                }
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store cache item', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $filename = $this->generateFilename($key);

        if (!$this->fileManager->exists($filename)) {
            return false;
        }

        $content = $this->fileManager->readSecurely($filename);
        if (null === $content) {
            return false;
        }

        try {
            $metadata = $this->serializer->unserialize($content);

            if (!is_array($metadata)) {
                return false;
            }

            if ($this->expirationManager->isExpired($metadata)) {
                $this->fileManager->deleteFile($filename);

                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $filename = $this->generateFilename($key);

        $result = $this->fileManager->deleteFile($filename);

        if ($result) {
            $this->logger->debug('Cache item deleted', [
                'key' => $key,
                'filename' => $filename,
            ]);
        }

        return $result;
    }

    private function generateFilename(string $key): string
    {
        $hash = hash(self::HASH_ALGORITHM, $key);
        $safeKey = $this->sanitizeKeyForFilename($key);

        $filename = $this->keyPrefix . $safeKey . '_' . substr($hash, 0, self::FILENAME_HASH_LENGTH) . '.cache';

        $directory = $this->directoryManager->createSubdirectoryPath($hash, $this->directoryLevels);

        return $directory . $filename;
    }

    private function sanitizeKeyForFilename(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);

        return substr($safeKey, 0, self::MAX_KEY_LENGTH_IN_FILENAME);
    }

    private function updateMetadataFile(string $filename, array $metadata): void
    {
        try {
            $serializedData = $this->serializer->serialize($metadata);
            $this->fileManager->writeSecurely($filename, $serializedData);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update access time', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function performCleanup(): void
    {
        $files = $this->directoryManager->getCacheFiles();
        $deletedCount = 0;

        foreach ($files as $file) {
            try {
                $content = $this->fileManager->readSecurely($file);
                if (null === $content) {
                    continue;
                }

                $metadata = $this->serializer->unserialize($content);

                if (is_array($metadata) && $this->expirationManager->isExpired($metadata)) {
                    $this->fileManager->deleteFile($file);
                    ++$deletedCount;
                }
            } catch (\Throwable) {
                // If we can't read/parse the file, consider it corrupted and delete it
                $this->fileManager->deleteFile($file);
                ++$deletedCount;
            }
        }

        if ($deletedCount > 0) {
            $this->logger->info('Cleanup completed', [
                'deleted_files' => $deletedCount,
            ]);
        }
    }
}
