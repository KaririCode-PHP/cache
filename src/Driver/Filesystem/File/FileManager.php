<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\File;

use KaririCode\Cache\Contract\Filesystem\FileManager as FileManagerContract;
use KaririCode\Cache\Driver\Filesystem\Config\FilesystemConfig;
use KaririCode\Contract\Logging\Logger;

/**
 * Thread-safe file manager with atomic operations.
 *
 * This class is solely responsible for secure file I/O operations, adhering
 * to the Single Responsibility Principle. It is configured via the central
 * FilesystemConfig DTO to ensure consistent behavior (e.g., file locking, permissions).
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FileManager implements FileManagerContract
{
    private const LOCK_TIMEOUT = 30; // seconds

    /**
     * @param FilesystemConfig $config the configuration DTO for the filesystem driver
     * @param Logger $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly FilesystemConfig $config,
        private readonly Logger $logger
    ) {
    }

    public function readSecurely(string $filename): ?string
    {
        if (!$this->exists($filename)) {
            return null;
        }

        $handle = $this->openFileForReading($filename);
        if (false === $handle) {
            return null;
        }

        try {
            if ($this->config->useLocking) {
                if (!flock($handle, LOCK_SH)) {
                    return null; // Failed to acquire shared lock
                }
            }

            $content = stream_get_contents($handle);

            return false !== $content ? $content : null;
        } finally {
            if ($this->config->useLocking) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    public function writeSecurely(string $filename, string $data): bool
    {
        $tempFile = $filename . '.tmp.' . bin2hex(random_bytes(6));
        $handle = $this->openFileForWriting($tempFile);

        if (false === $handle) {
            return false;
        }

        try {
            if ($this->config->useLocking) {
                if (!flock($handle, LOCK_EX)) {
                    return false; // Failed to acquire exclusive lock
                }
            }

            if (fwrite($handle, $data) !== strlen($data)) {
                return false;
            }

            if (!fflush($handle)) {
                return false;
            }

            return $this->atomicMove($tempFile, $filename);
        } finally {
            if ($this->config->useLocking) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            $this->cleanupTempFile($tempFile);
        }
    }

    public function deleteFile(string $filename): bool
    {
        if (!file_exists($filename)) {
            return true;
        }

        return @unlink($filename);
    }

    public function exists(string $filename): bool
    {
        return is_file($filename) && is_readable($filename);
    }

    public function getSize(string $filename): int
    {
        if (!$this->exists($filename)) {
            return 0;
        }
        $size = filesize($filename);

        return false !== $size ? $size : 0;
    }

    public function getModificationTime(string $filename): int
    {
        if (!$this->exists($filename)) {
            return 0;
        }
        $mtime = filemtime($filename);

        return false !== $mtime ? $mtime : 0;
    }

    /**
     * @return resource|false
     */
    private function openFileForReading(string $filename)
    {
        try {
            return fopen($filename, 'rb');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to open file for reading', ['filename' => $filename, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return resource|false
     */
    private function openFileForWriting(string $filename)
    {
        try {
            return fopen($filename, 'wb');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to open file for writing', ['filename' => $filename, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function atomicMove(string $source, string $destination): bool
    {
        try {
            if (rename($source, $destination)) {
                // Apply permissions from the central configuration.
                @chmod($destination, $this->config->filePermissions);

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to atomically move file', ['source' => $source, 'destination' => $destination, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function cleanupTempFile(string $tempFile): void
    {
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
}
