<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise-grade filesystem cache driver with comprehensive security and performance optimizations.
 *
 * Features:
 * - Thread-safe operations with file locking
 * - Secure file handling with atomic writes
 * - Efficient directory structure with multiple levels
 * - Automatic cleanup and size management
 * - Comprehensive error handling and logging
 * - Memory-efficient operations for large datasets
 * - Security validation and sanitization
 *
 * Performance Optimizations:
 * - Multi-level directory structure for better I/O
 * - Atomic file operations to prevent corruption
 * - LRU-based cleanup algorithm
 * - Efficient metadata handling
 * - Optimized serialization with compression
 *
 * Security Features:
 * - Path traversal prevention
 * - File permission validation
 * - Size limits and quotas
 * - Secure filename generation
 * - Access logging and monitoring
 *
 * @see https://www.php.net/manual/en/book.filesystem.php PHP Filesystem Functions
 * @see https://tools.ietf.org/html/rfc3986 RFC 3986 - URI Generic Syntax (for safe filenames)
 */
final class FilesystemDriver implements CacheDriver
{
    private string $directory;
    private string $keyPrefix;
    private int $maxSize;
    private float $cleanupProbability;
    private LoggerInterface $logger;
    private int $directoryLevels;
    private bool $useCompression;
    private bool $useLocking;

    // Security and performance limits
    private const MAX_KEY_LENGTH = 200; // Shorter than Redis to account for filename limits
    private const MAX_VALUE_SIZE = 100 * 1024 * 1024; // 100MB
    private const MAX_FILENAME_LENGTH = 255;
    private const CLEANUP_BATCH_SIZE = 1000;
    private const LOCK_TIMEOUT = 30;

    // Directory permissions
    private const DIR_PERMISSIONS = 0755;
    private const FILE_PERMISSIONS = 0644;

    public function __construct(
        string $directory,
        string $keyPrefix = 'cache_',
        int $maxSize = 100 * 1024 * 1024, // 100MB
        float $cleanupProbability = 0.01, // 1% chance
        int $directoryLevels = 2,
        bool $useCompression = true,
        bool $useLocking = true,
        ?LoggerInterface $logger = null
    ) {
        $this->directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
        $this->keyPrefix = $this->sanitizePrefix($keyPrefix);
        $this->maxSize = max(1024 * 1024, $maxSize); // Minimum 1MB
        $this->cleanupProbability = max(0.0, min(1.0, $cleanupProbability));
        $this->directoryLevels = max(1, min(4, $directoryLevels)); // 1-4 levels
        $this->useCompression = $useCompression;
        $this->useLocking = $useLocking;
        $this->logger = $logger ?? new NullLogger();

        $this->initializeDirectory();
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $this->performPeriodicCleanup();

        $filename = $this->getFilename($key);

        if (!$this->isValidCacheFile($filename)) {
            return null;
        }

        try {
            $data = $this->readFileSecurely($filename);

            if (null === $data) {
                return null;
            }

            // Check expiration
            if ($this->isExpiredData($data)) {
                $this->deleteFile($filename);

                return null;
            }

            // Update access time for LRU
            $this->touchFile($filename);

            return $this->unserializeData($data['value']);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read cache file', [
                'key' => $key,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            // Clean up potentially corrupted file
            $this->deleteFile($filename);

            return null;
        }
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->performPeriodicCleanup();

        $filename = $this->getFilename($key);

        try {
            // Check if we need to make space
            if (!$this->hasSpaceForValue($value, $filename)) {
                if (!$this->makeSpace($value, $filename)) {
                    throw CacheException::storageError('Insufficient space for cache item');
                }
            }

            $data = [
                'value' => $this->serializeData($value),
                'expiration' => $expiration ? $expiration->getTimestamp() : null,
                'created' => time(),
                'accessed' => time(),
                'size' => 0, // Will be calculated after serialization
            ];

            $serializedData = $this->serializeData($data);
            $data['size'] = strlen($serializedData);

            $success = $this->writeFileSecurely($filename, $serializedData);

            if ($success) {
                $this->logger->debug('Cache file written', [
                    'key' => $key,
                    'file' => $filename,
                    'size' => $data['size'],
                    'compressed' => $this->useCompression,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write cache file', [
                'key' => $key,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        $filename = $this->getFilename($key);

        if (!$this->isValidCacheFile($filename)) {
            return false;
        }

        try {
            $data = $this->readFileSecurely($filename);

            if (null === $data) {
                return false;
            }

            if ($this->isExpiredData($data)) {
                $this->deleteFile($filename);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check cache file', [
                'key' => $key,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $filename = $this->getFilename($key);

        try {
            $result = $this->deleteFile($filename);

            if ($result) {
                $this->logger->debug('Cache file deleted', [
                    'key' => $key,
                    'file' => $filename,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete cache file', [
                'key' => $key,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $deletedCount = $this->clearDirectory($this->directory);

            $this->logger->info('Cache directory cleared', [
                'directory' => $this->directory,
                'files_deleted' => $deletedCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear cache directory', [
                'directory' => $this->directory,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);

        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $expiration)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function hasCapability(string $capability): bool
    {
        return match ($capability) {
            CacheCapabilities::ATOMIC_OPERATIONS => false,
            CacheCapabilities::TAGGING => false,
            CacheCapabilities::TOPICS => false,
            CacheCapabilities::TRANSACTIONS => false,
            default => false,
        };
    }

    /**
     * Get filesystem cache statistics.
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'directory' => $this->directory,
                'directory_size' => $this->getDirectorySize(),
                'file_count' => $this->getFileCount(),
                'max_size' => $this->maxSize,
                'available_space' => disk_free_space($this->directory),
                'total_space' => disk_total_space($this->directory),
                'directory_levels' => $this->directoryLevels,
                'compression_enabled' => $this->useCompression,
                'locking_enabled' => $this->useLocking,
            ];

            $stats['usage_percent'] = $this->maxSize > 0
                ? round(($stats['directory_size'] / $this->maxSize) * 100, 2)
                : 0;

            return $stats;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'directory' => $this->directory,
            ];
        }
    }

    /**
     * Force cleanup of expired files.
     */
    public function cleanup(): int
    {
        return $this->cleanupExpiredFiles();
    }

    // Private helper methods...

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }

        // Check for path traversal attempts
        if (false !== strpos($key, '..') || false !== strpos($key, '/') || false !== strpos($key, '\\')) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains invalid path characters)');
        }

        // Check for control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains control characters)');
        }
    }

    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        $serialized = $this->serializeData($value);

        if (strlen($serialized) > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Value too large: ' . strlen($serialized) . ' bytes');
        }
    }

    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
            $this->validateValue($value);
        }
    }

    private function sanitizePrefix(string $prefix): string
    {
        // Remove any path separators and control characters
        $sanitized = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F\x7F]/', '_', $prefix);

        return substr($sanitized, 0, 50); // Limit length
    }

    private function getFilename(string $key): string
    {
        $hash = hash('sha256', $key);
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $safeKey = substr($safeKey, 0, 50); // Limit length

        $filename = $this->keyPrefix . $safeKey . '_' . substr($hash, 0, 16) . '.cache';

        // Create directory structure
        $subdirs = [];
        for ($i = 0; $i < $this->directoryLevels; ++$i) {
            $subdirs[] = substr($hash, $i * 2, 2);
        }

        $directory = $this->directory . implode(DIRECTORY_SEPARATOR, $subdirs) . DIRECTORY_SEPARATOR;
        $this->ensureDirectoryExists($directory);

        return $directory . $filename;
    }

    private function initializeDirectory(): void
    {
        if (!$this->ensureDirectoryExists($this->directory)) {
            throw CacheException::directoryNotWritable($this->directory);
        }

        // Verify write permissions
        if (!is_writable($this->directory)) {
            throw CacheException::directoryNotWritable($this->directory);
        }

        $this->logger->info('Filesystem cache initialized', [
            'directory' => $this->directory,
            'max_size' => $this->maxSize,
            'directory_levels' => $this->directoryLevels,
            'compression' => $this->useCompression,
            'locking' => $this->useLocking,
        ]);
    }

    private function ensureDirectoryExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        try {
            return mkdir($directory, self::DIR_PERMISSIONS, true);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create directory', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function isValidCacheFile(string $filename): bool
    {
        return is_file($filename) && is_readable($filename);
    }

    private function readFileSecurely(string $filename): ?array
    {
        $handle = $this->openFileForReading($filename);

        if (false === $handle) {
            return null;
        }

        try {
            if ($this->useLocking) {
                if (!flock($handle, LOCK_SH)) {
                    return null;
                }
            }

            $content = stream_get_contents($handle);

            if (false === $content) {
                return null;
            }

            $data = $this->unserializeData($content);

            if (!is_array($data) || !isset($data['value'])) {
                return null;
            }

            return $data;
        } finally {
            if ($this->useLocking) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function writeFileSecurely(string $filename, string $data): bool
    {
        $tempFile = $filename . '.tmp.' . uniqid() . '.pid' . getmypid();

        try {
            $handle = $this->openFileForWriting($tempFile);

            if (false === $handle) {
                return false;
            }

            try {
                if ($this->useLocking) {
                    if (!flock($handle, LOCK_EX)) {
                        return false;
                    }
                }

                $bytesWritten = fwrite($handle, $data);

                if (false === $bytesWritten || $bytesWritten !== strlen($data)) {
                    return false;
                }

                if (!fflush($handle)) {
                    return false;
                }

                if (!fsync($handle)) {
                    return false;
                }
            } finally {
                if ($this->useLocking) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }

            // Atomic move
            if (!rename($tempFile, $filename)) {
                unlink($tempFile);

                return false;
            }

            // Set proper permissions
            chmod($filename, self::FILE_PERMISSIONS);

            return true;
        } catch (\Throwable $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            $this->logger->error('Failed to write file securely', [
                'filename' => $filename,
                'temp_file' => $tempFile,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteFile(string $filename): bool
    {
        if (!file_exists($filename)) {
            return true;
        }

        try {
            return unlink($filename);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function touchFile(string $filename): void
    {
        try {
            touch($filename);
        } catch (\Throwable $e) {
            // Non-critical operation, just log
            $this->logger->debug('Failed to update file access time', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function openFileForReading(string $filename): mixed
    {
        try {
            return fopen($filename, 'rb');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to open file for reading', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function openFileForWriting(string $filename): mixed
    {
        try {
            return fopen($filename, 'wb');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to open file for writing', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function serializeData(mixed $data): string
    {
        try {
            $serialized = serialize($data);

            if ($this->useCompression && strlen($serialized) > 1024) {
                $compressed = gzcompress($serialized, 6);
                if (false !== $compressed && strlen($compressed) < strlen($serialized) * 0.9) {
                    return 'gz:' . $compressed;
                }
            }

            return 'raw:' . $serialized;
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed('filesystem', $e);
        }
    }

    private function unserializeData(string $data): mixed
    {
        try {
            if (str_starts_with($data, 'gz:')) {
                $decompressed = gzuncompress(substr($data, 3));
                if (false === $decompressed) {
                    throw new \RuntimeException('Decompression failed');
                }

                return unserialize($decompressed);
            }

            if (str_starts_with($data, 'raw:')) {
                return unserialize(substr($data, 4));
            }

            // Legacy data without prefix
            return unserialize($data);
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('filesystem', $e);
        }
    }

    private function isExpiredData(array $data): bool
    {
        if (!isset($data['expiration']) || null === $data['expiration']) {
            return false;
        }

        return $data['expiration'] < time();
    }

    private function hasSpaceForValue(mixed $value, string $filename): bool
    {
        $valueSize = strlen($this->serializeData($value));
        $currentSize = $this->getDirectorySize();
        $existingFileSize = file_exists($filename) ? filesize($filename) : 0;

        $newSize = $currentSize - $existingFileSize + $valueSize;

        return $newSize <= $this->maxSize;
    }

    private function makeSpace(mixed $value, string $filename): bool
    {
        // First, cleanup expired files
        $cleaned = $this->cleanupExpiredFiles();

        if ($this->hasSpaceForValue($value, $filename)) {
            return true;
        }

        // Use LRU eviction
        return $this->evictLRUFiles($value, $filename);
    }

    private function evictLRUFiles(mixed $value, string $filename): bool
    {
        $valueSize = strlen($this->serializeData($value));
        $existingFileSize = file_exists($filename) ? filesize($filename) : 0;
        $neededSpace = $valueSize - $existingFileSize;

        if ($neededSpace <= 0) {
            return true;
        }

        $files = $this->getCacheFiles();

        // Sort by access time (oldest first)
        usort($files, function ($a, $b) {
            return fileatime($a) <=> fileatime($b);
        });

        $freedSpace = 0;

        foreach ($files as $file) {
            if ($file === $filename) {
                continue; // Don't delete the file we're trying to write
            }

            $fileSize = filesize($file);
            $this->deleteFile($file);
            $freedSpace += $fileSize;

            if ($freedSpace >= $neededSpace) {
                return true;
            }
        }

        return false;
    }

    private function performPeriodicCleanup(): void
    {
        if (mt_rand() / mt_getrandmax() < $this->cleanupProbability) {
            $this->cleanupExpiredFiles();
        }
    }

    private function cleanupExpiredFiles(): int
    {
        $files = $this->getCacheFiles();
        $deletedCount = 0;
        $now = time();

        foreach ($files as $file) {
            try {
                $data = $this->readFileSecurely($file);

                if ($data && $this->isExpiredData($data)) {
                    $this->deleteFile($file);
                    ++$deletedCount;
                }
            } catch (\Throwable $e) {
                // If we can't read the file, consider it corrupted and delete it
                $this->deleteFile($file);
                ++$deletedCount;
            }
        }

        if ($deletedCount > 0) {
            $this->logger->info('Cleaned up expired cache files', [
                'deleted_count' => $deletedCount,
            ]);
        }

        return $deletedCount;
    }

    private function getCacheFiles(): array
    {
        $files = [];
        $pattern = $this->keyPrefix . '*.cache';

        $this->scanDirectory($this->directory, $pattern, $files);

        return $files;
    }

    private function scanDirectory(string $directory, string $pattern, array &$files): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scan directory', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clearDirectory(string $directory): int
    {
        $deletedCount = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                    ++$deletedCount;
                } elseif ($file->isDir()) {
                    rmdir($file->getPathname());
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear directory', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
        }

        return $deletedCount;
    }

    private function getDirectorySize(): int
    {
        $size = 0;
        $files = $this->getCacheFiles();

        foreach ($files as $file) {
            $size += filesize($file);
        }

        return $size;
    }

    private function getFileCount(): int
    {
        return count($this->getCacheFiles());
    }
}
