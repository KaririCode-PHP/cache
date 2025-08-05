<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\ExpirationManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use Psr\Log\LoggerInterface;

/**
 * Handles basic CRUD cache operations for FilesystemDriver with ExpirationProvider support.
 *
 * This class manages filesystem-based cache operations with proper TTL management
 * using the Strategy Pattern through ExpirationProvider. Follows Clean Architecture
 * principles and SOLID design patterns.
 *
 * Design Patterns Applied:
 * - Strategy Pattern: ExpirationProvider for TTL management
 * - Single Responsibility: Handles only CRUD operations
 * - Dependency Injection: All dependencies injected via constructor
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FilesystemCrudOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    private const HASH_ALGORITHM = 'sha256';
    private const FILENAME_HASH_LENGTH = 8;
    private const MAX_KEY_LENGTH_IN_FILENAME = 50;

    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param DirectoryManager $directoryManager Directory operations handler
     * @param FileManager $fileManager File operations handler
     * @param CacheSerializer $serializer Value serialization handler
     * @param ExpirationManager $expirationManager Expiration metadata handler
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     * @param LoggerInterface $logger PSR-3 logger for debugging and monitoring
     * @param string $keyPrefix Prefix for cache keys
     * @param int $directoryLevels Number of subdirectory levels for distribution
     */
    public function __construct(
        private readonly DirectoryManager $directoryManager,
        private readonly FileManager $fileManager,
        private readonly CacheSerializer $serializer,
        private readonly ExpirationManager $expirationManager,
        private readonly ExpirationProvider $expirationProvider,
        private readonly LoggerInterface $logger,
        private readonly string $keyPrefix = 'cache_',
        private readonly int $directoryLevels = 2
    ) {
    }

    /**
     * Retrieves a cache item from the filesystem.
     *
     * @param string $key The cache key to retrieve
     *
     * @throws CacheException if the operation fails
     *
     * @return mixed The cached value or null if not found or expired
     */
    public function get(string $key): mixed
    {
        $this->validateKey($key);

        try {
            $filename = $this->generateFilename($key);

            if (!$this->fileManager->exists($filename)) {
                $this->logger->debug('Cache miss: file not found', [
                    'key' => $key,
                    'filename' => $filename,
                ]);

                return null;
            }

            $data = $this->fileManager->read($filename);
            if (null === $data) {
                $this->logger->warning('Failed to read cache file', [
                    'key' => $key,
                    'filename' => $filename,
                ]);

                return null;
            }

            $metadata = $this->serializer->unserialize($data);
            if (!is_array($metadata) || !isset($metadata['value'])) {
                $this->logger->warning('Invalid cache file format', [
                    'key' => $key,
                    'filename' => $filename,
                ]);
                $this->fileManager->delete($filename);

                return null;
            }

            // Check expiration
            if ($this->expirationManager->isExpired($metadata)) {
                $this->logger->debug('Cache miss: expired entry', [
                    'key' => $key,
                    'expiration' => $metadata['expiration'] ?? null,
                ]);
                $this->fileManager->delete($filename);

                return null;
            }

            // Update access time
            $this->expirationManager->updateAccessTime($metadata);
            $this->updateMetadataFile($filename, $metadata);

            $this->logger->debug('Cache hit', [
                'key' => $key,
                'size' => $metadata['size'] ?? 0,
            ]);

            return $metadata['value'];
        } catch (\Throwable $e) {
            $this->logger->error('Cache get operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('get', $key, $e);
        }
    }

    /**
     * Stores a cache item in the filesystem with TTL support.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param \DateTimeInterface|null $expiration Optional expiration time
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the item was successfully stored
     */
    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        try {
            // Calculate expiration using ExpirationProvider
            $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
                $this->expirationProvider->getTtlForKey($key)
            );

            $filename = $this->generateFilename($key);
            $directory = dirname($filename);

            // Ensure directory exists
            if (!$this->directoryManager->ensureDirectoryExists($directory)) {
                throw new CacheException("Failed to create cache directory: {$directory}");
            }

            // Create metadata
            $metadata = $this->expirationManager->createMetadata($value, $finalExpiration);
            $metadata['size'] = $this->calculateSize($value);

            // Serialize and write atomically
            $serializedData = $this->serializer->serialize($metadata);
            $success = $this->fileManager->write($filename, $serializedData);

            if ($success) {
                $this->logger->debug('Cache set successful', [
                    'key' => $key,
                    'size' => $metadata['size'],
                    'expiration' => $finalExpiration?->format('Y-m-d H:i:s'),
                ]);
            } else {
                $this->logger->error('Cache set failed: write operation failed', [
                    'key' => $key,
                    'filename' => $filename,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Cache set operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('set', $key, $e);
        }
    }

    /**
     * Checks if a cache item exists and is not expired.
     *
     * @param string $key The cache key to check
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the key exists and is not expired
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        try {
            $filename = $this->generateFilename($key);

            if (!$this->fileManager->exists($filename)) {
                return false;
            }

            $data = $this->fileManager->read($filename);
            if (null === $data) {
                return false;
            }

            $metadata = $this->serializer->unserialize($data);
            if (!is_array($metadata)) {
                // Invalid metadata, clean up the file
                $this->fileManager->delete($filename);

                return false;
            }

            // Check expiration
            if ($this->expirationManager->isExpired($metadata)) {
                $this->fileManager->delete($filename);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Cache has operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('has', $key, $e);
        }
    }

    /**
     * Deletes a cache item from the filesystem.
     *
     * @param string $key The cache key to delete
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the item was successfully deleted or didn't exist
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        try {
            $filename = $this->generateFilename($key);

            if (!$this->fileManager->exists($filename)) {
                $this->logger->debug('Cache delete: key not found', ['key' => $key]);

                return true; // Key doesn't exist, consider it deleted
            }

            $success = $this->fileManager->delete($filename);

            if ($success) {
                $this->logger->debug('Cache delete successful', ['key' => $key]);
            } else {
                $this->logger->error('Cache delete failed', [
                    'key' => $key,
                    'filename' => $filename,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Cache delete operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('delete', $key, $e);
        }
    }

    /**
     * Clears all cache items from the filesystem.
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true if the cache was successfully cleared
     */
    public function clear(): bool
    {
        try {
            $cleared = $this->directoryManager->clear();

            if ($cleared) {
                $this->logger->info('Cache cleared successfully');
            } else {
                $this->logger->error('Cache clear operation failed');
            }

            return $cleared;
        } catch (\Throwable $e) {
            $this->logger->error('Cache clear operation failed', [
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('clear', '', $e);
        }
    }

    /**
     * Cleans up expired cache entries.
     *
     * @return array<string, int> Cleanup statistics
     */
    public function cleanupExpired(): array
    {
        $stats = [
            'scanned' => 0,
            'expired' => 0,
            'deleted' => 0,
            'errors' => 0,
        ];

        try {
            $iterator = $this->directoryManager->getIterator();

            foreach ($iterator as $file) {
                ++$stats['scanned'];

                try {
                    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.cache')) {
                        continue;
                    }

                    $data = $this->fileManager->read($file->getPathname());
                    if (null === $data) {
                        ++$stats['errors'];
                        continue;
                    }

                    $metadata = $this->serializer->unserialize($data);
                    if (!is_array($metadata)) {
                        ++$stats['errors'];
                        continue;
                    }

                    if ($this->expirationManager->isExpired($metadata)) {
                        ++$stats['expired'];

                        if ($this->fileManager->delete($file->getPathname())) {
                            ++$stats['deleted'];
                        } else {
                            ++$stats['errors'];
                        }
                    }
                } catch (\Throwable $e) {
                    ++$stats['errors'];
                    $this->logger->warning('Error during cleanup of cache file', [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Cache cleanup completed', $stats);
        } catch (\Throwable $e) {
            $this->logger->error('Cache cleanup failed', [
                'error' => $e->getMessage(),
            ]);
            ++$stats['errors'];
        }

        return $stats;
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Generates a secure filename for a cache key.
     */
    private function generateFilename(string $key): string
    {
        $hash = hash(self::HASH_ALGORITHM, $key);
        $safeKey = $this->sanitizeKeyForFilename($key);

        $filename = $this->keyPrefix . $safeKey . '_' . substr($hash, 0, self::FILENAME_HASH_LENGTH) . '.cache';
        $directory = $this->directoryManager->createSubdirectoryPath($hash, $this->directoryLevels);

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Sanitizes a cache key for use in filenames.
     */
    private function sanitizeKeyForFilename(string $key): string
    {
        // Replace invalid filename characters with underscores
        $safeKey = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);

        // Limit length to prevent filesystem issues
        return substr($safeKey, 0, self::MAX_KEY_LENGTH_IN_FILENAME);
    }

    /**
     * Updates metadata in a cache file.
     */
    private function updateMetadataFile(string $filename, array $metadata): void
    {
        try {
            $serializedData = $this->serializer->serialize($metadata);
            $this->fileManager->write($filename, $serializedData);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update cache file metadata', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculates the approximate size of a value.
     */
    private function calculateSize(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        if (is_numeric($value)) {
            return 8; // Approximate size for numbers
        }

        if (is_bool($value) || is_null($value)) {
            return 1;
        }

        // For complex types, use serialized size as approximation
        try {
            $serialized = $this->serializer->serialize($value);

            return strlen($serialized);
        } catch (\Throwable) {
            return 0; // Fallback for unsupported types
        }
    }
}
