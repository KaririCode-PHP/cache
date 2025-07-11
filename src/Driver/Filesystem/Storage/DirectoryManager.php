<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Storage;

use KaririCode\Cache\Contract\Filesystem\DirectoryManager as DirectoryManagerContract;
use KaririCode\Cache\Exception\CacheException;
use Psr\Log\LoggerInterface;

/**
 * Directory manager following SRP.
 * Responsible ONLY for directory management operations.
 */
final class DirectoryManager implements DirectoryManagerContract
{
    private const DIR_PERMISSIONS = 0755;
    private const CLEANUP_BATCH_SIZE = 1000;

    public function __construct(
        private string $baseDirectory,
        private string $keyPrefix = 'cache_',
        private LoggerInterface $logger
    ) {
        $this->baseDirectory = rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $this->initializeBaseDirectory();
    }

    public function ensureExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        try {
            $result = mkdir($directory, self::DIR_PERMISSIONS, true);

            if ($result) {
                $this->logger->debug('Directory created', [
                    'directory' => $directory,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create directory', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $deletedCount = $this->clearDirectoryRecursive($this->baseDirectory);

            $this->logger->info('Cache directory cleared', [
                'directory' => $this->baseDirectory,
                'files_deleted' => $deletedCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear cache directory', [
                'directory' => $this->baseDirectory,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getSize(): int
    {
        $size = 0;
        $files = $this->getCacheFiles();

        foreach ($files as $file) {
            $fileSize = filesize($file);
            if (false !== $fileSize) {
                $size += $fileSize;
            }
        }

        return $size;
    }

    public function getFileCount(): int
    {
        return count($this->getCacheFiles());
    }

    public function isWritable(string $directory): bool
    {
        return is_dir($directory) && is_writable($directory);
    }

    /**
     * Get all cache files matching the prefix pattern.
     */
    public function getCacheFiles(): array
    {
        $files = [];
        $pattern = $this->keyPrefix . '*.cache';

        $this->scanDirectory($this->baseDirectory, $pattern, $files);

        return $files;
    }

    /**
     * Create subdirectory structure for better performance.
     */
    public function createSubdirectoryPath(string $hash, int $levels = 2): string
    {
        $subdirs = [];
        for ($i = 0; $i < $levels; ++$i) {
            $subdirs[] = substr($hash, $i * 2, 2);
        }

        $directory = $this->baseDirectory . implode(DIRECTORY_SEPARATOR, $subdirs) . DIRECTORY_SEPARATOR;
        $this->ensureExists($directory);

        return $directory;
    }

    private function initializeBaseDirectory(): void
    {
        if (!$this->ensureExists($this->baseDirectory)) {
            throw CacheException::directoryNotWritable($this->baseDirectory);
        }

        if (!$this->isWritable($this->baseDirectory)) {
            throw CacheException::directoryNotWritable($this->baseDirectory);
        }

        $this->logger->info('Directory manager initialized', [
            'base_directory' => $this->baseDirectory,
            'key_prefix' => $this->keyPrefix,
        ]);
    }

    private function clearDirectoryRecursive(string $directory): int
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
            $this->logger->error('Failed to clear directory recursively', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
        }

        return $deletedCount;
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
}
