<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Directory;

use KaririCode\Cache\Contract\Filesystem\DirectoryManager as DirectoryManagerContract;
use KaririCode\Cache\Driver\Filesystem\Config\FilesystemConfig;
use KaririCode\Cache\Exception\FilesystemException;
use KaririCode\Contract\Logging\Logger;

/**
 * Manages all directory-related operations for the FilesystemDriver.
 *
 * This class is solely responsible for creating, clearing, and inspecting
 * cache directories. It adheres to the Single Responsibility Principle and
 * is configured via a dedicated DTO for consistency and type safety.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class DirectoryManager implements DirectoryManagerContract
{
    private readonly string $baseDirectory;

    /**
     * @param FilesystemConfig $config the configuration DTO for the filesystem driver
     * @param Logger $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly FilesystemConfig $config,
        private readonly Logger $logger
    ) {
        $this->baseDirectory = rtrim($this->config->directory, '/\\') . DIRECTORY_SEPARATOR;
        $this->initializeBaseDirectory();
    }

    public function ensureExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        try {
            $result = mkdir($directory, $this->config->directoryPermissions, true);
            if ($result) {
                $this->logger->debug('Cache directory created', ['directory' => $directory]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create directory', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
            throw FilesystemException::directoryCreationFailed($directory, $e);
        }
    }

    public function clear(): bool
    {
        try {
            $deletedCount = $this->clearDirectoryRecursive($this->baseDirectory);
            $this->logger->info('Cache directory cleared', [
                'directory' => $this->baseDirectory,
                'items_deleted' => $deletedCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear cache directory', [
                'directory' => $this->baseDirectory,
                'error' => $e->getMessage(),
            ]);
            // Use a more specific exception
            throw FilesystemException::directoryClearFailed($this->baseDirectory, $e);
        }
    }

    public function getSize(): int
    {
        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->baseDirectory, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not calculate directory size.', ['error' => $e->getMessage()]);
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

    public function getCacheFiles(): array
    {
        $files = [];
        if (!is_dir($this->baseDirectory)) {
            return [];
        }
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->baseDirectory, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not scan cache files.', ['error' => $e->getMessage()]);
        }

        return $files;
    }

    public function createSubdirectoryPath(string $hash): string
    {
        $levels = $this->config->directoryLevels;
        if ($levels <= 0) {
            return $this->baseDirectory;
        }

        $subdirs = [];
        for ($i = 0; $i < $levels; ++$i) {
            $subdirs[] = substr($hash, $i * 2, 2);
        }

        $directory = $this->baseDirectory . implode(DIRECTORY_SEPARATOR, $subdirs);

        if (!$this->ensureExists($directory)) {
            throw FilesystemException::directoryCreationFailed($directory);
        }

        return $directory;
    }

    public function getBaseDirectory(): string
    {
        return $this->baseDirectory;
    }

    private function initializeBaseDirectory(): void
    {
        if (!$this->ensureExists($this->baseDirectory)) {
            // Exception is already thrown by ensureExists
            return;
        }
        if (!$this->isWritable($this->baseDirectory)) {
            throw FilesystemException::directoryNotWritable($this->baseDirectory);
        }
    }

    private function clearDirectoryRecursive(string $directory): int
    {
        $deletedCount = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                if (rmdir($file->getRealPath())) {
                    ++$deletedCount;
                }
            } else {
                if (unlink($file->getRealPath())) {
                    ++$deletedCount;
                }
            }
        }

        return $deletedCount;
    }
}
