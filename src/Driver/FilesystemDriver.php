<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Exception\CacheException;

/**
 * Filesystem driver with automatic cleanup and size management.
 */
final class FilesystemDriver implements CacheDriver
{
    private string $directory;
    private string $keyPrefix;
    private int $maxSize;
    private float $cleanupProbability;

    public function __construct(
        string $directory,
        string $keyPrefix = 'cache_',
        int $maxSize = 100 * 1024 * 1024, // 100MB
        float $cleanupProbability = 0.01 // 1% chance
    ) {
        $this->directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
        $this->keyPrefix = $keyPrefix;
        $this->maxSize = $maxSize;
        $this->cleanupProbability = $cleanupProbability;

        $this->ensureDirectoryExists();
    }

    public function get(string $key): mixed
    {
        $filename = $this->getFilename($key);

        if (!file_exists($filename)) {
            return null;
        }

        $data = $this->readFile($filename);

        if (null === $data) {
            return null;
        }

        // Check expiration
        if (isset($data['expiration']) && $data['expiration'] < time()) {
            $this->delete($key);

            return null;
        }

        // Update access time for LRU
        touch($filename);

        return $data['value'];
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $filename = $this->getFilename($key);

        $data = [
            'value' => $value,
            'expiration' => $expiration ? $expiration->getTimestamp() : null,
            'created' => time(),
        ];

        $result = $this->writeFile($filename, $data);

        // Probabilistic cleanup
        if ($result && mt_rand() / mt_getrandmax() < $this->cleanupProbability) {
            $this->cleanup();
        }

        return $result;
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    public function delete(string $key): bool
    {
        $filename = $this->getFilename($key);

        if (file_exists($filename)) {
            return unlink($filename);
        }

        return true;
    }

    public function clear(): bool
    {
        $pattern = $this->directory . $this->keyPrefix . '*';
        $files = glob($pattern);

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        // File-based increment with file locking
        $filename = $this->getFilename($key);
        $lockFile = $filename . '.lock';

        $fp = fopen($lockFile, 'w');
        if (!$fp || !flock($fp, LOCK_EX)) {
            throw new CacheException('Unable to acquire lock for increment operation');
        }

        try {
            $current = $this->get($key) ?? $initialValue;
            $newValue = (int) $current + $step;

            $expiration = $ttl ? new \DateTime('+' . $ttl . ' seconds') : null;
            $this->set($key, $newValue, $expiration);

            return $newValue;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            unlink($lockFile);
        }
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        $expiration = $ttl ? new \DateTime('+' . $ttl . ' seconds') : null;

        return $this->set($key, $value, $expiration);
    }

    public function invalidateTags(array $tags): bool
    {
        // Filesystem doesn't support native tagging
        // This would require a metadata file to track tag associations
        trigger_error(
            'Filesystem driver does not support efficient tag invalidation.',
            E_USER_WARNING
        );

        return true;
    }

    public function subscribeTo(string $key, array $topics): void
    {
        // No-op for filesystem driver
    }

    public function invalidateTopics(array $topics): bool
    {
        return true;
    }

    private function getFilename(string $key): string
    {
        return $this->directory . $this->keyPrefix . hash('sha256', $key) . '.cache';
    }

    private function readFile(string $filename): ?array
    {
        $content = file_get_contents($filename);

        if (false === $content) {
            return null;
        }

        $data = unserialize($content);

        if (false === $data) {
            unlink($filename); // Remove corrupted file

            return null;
        }

        return $data;
    }

    private function writeFile(string $filename, array $data): bool
    {
        $tempFile = $filename . '.tmp.' . uniqid();

        $result = file_put_contents($tempFile, serialize($data), LOCK_EX);

        if (false === $result) {
            return false;
        }

        return rename($tempFile, $filename);
    }

    private function cleanup(): void
    {
        // Check total directory size
        $totalSize = $this->getDirectorySize();

        if ($totalSize <= $this->maxSize) {
            // Clean up expired files
            $this->cleanupExpired();

            return;
        }

        // Size exceeded, perform LRU cleanup
        $this->cleanupLRU();
    }

    private function getDirectorySize(): int
    {
        $size = 0;
        $pattern = $this->directory . $this->keyPrefix . '*.cache';

        foreach (glob($pattern) as $file) {
            $size += filesize($file);
        }

        return $size;
    }

    private function cleanupExpired(): void
    {
        $pattern = $this->directory . $this->keyPrefix . '*.cache';
        $now = time();

        foreach (glob($pattern) as $file) {
            $data = $this->readFile($file);

            if ($data && isset($data['expiration']) && $data['expiration'] < $now) {
                unlink($file);
            }
        }
    }

    private function cleanupLRU(): void
    {
        $pattern = $this->directory . $this->keyPrefix . '*.cache';
        $files = [];

        foreach (glob($pattern) as $file) {
            $files[] = [
                'file' => $file,
                'atime' => fileatime($file),
                'size' => filesize($file),
            ];
        }

        // Sort by access time (oldest first)
        usort($files, fn ($a, $b) => $a['atime'] <=> $b['atime']);

        $currentSize = $this->getDirectorySize();
        $targetSize = $this->maxSize * 0.8; // Clean up to 80% of max size

        foreach ($files as $fileInfo) {
            if ($currentSize <= $targetSize) {
                break;
            }

            unlink($fileInfo['file']);
            $currentSize -= $fileInfo['size'];
        }
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true)) {
            throw new CacheException("Unable to create cache directory: {$this->directory}");
        }
    }
}
