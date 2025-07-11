<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\File;

use KaririCode\Cache\Contract\Filesystem\FileManager as FileManagerContract;
use KaririCode\Cache\Exception\CacheException;
use Psr\Log\LoggerInterface;

/**
 * Thread-safe file manager with atomic operations.
 * Follows SRP - responsible ONLY for file I/O operations.
 */
final class FileManager implements FileManagerContract
{
    private const LOCK_TIMEOUT = 30;
    private const FILE_PERMISSIONS = 0644;

    public function __construct(
        private bool $useLocking = true,
        private LoggerInterface $logger
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
            if ($this->useLocking && !flock($handle, LOCK_SH, $wouldBlock)) {
                if ($wouldBlock) {
                    throw CacheException::lockTimeout($filename, self::LOCK_TIMEOUT);
                }

                return null;
            }

            $content = stream_get_contents($handle);

            return false !== $content ? $content : null;
        } finally {
            if ($this->useLocking) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    public function writeSecurely(string $filename, string $data): bool
    {
        $tempFile = $filename . '.tmp.' . uniqid() . '.pid' . getmypid();

        try {
            $handle = $this->openFileForWriting($tempFile);
            if (false === $handle) {
                return false;
            }

            try {
                if ($this->useLocking && !flock($handle, LOCK_EX)) {
                    return false;
                }

                $bytesWritten = fwrite($handle, $data);
                $success = $bytesWritten === strlen($data);

                if ($success && !fflush($handle)) {
                    $success = false;
                }

                return $success && $this->atomicMove($tempFile, $filename);
            } finally {
                if ($this->useLocking) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
        } catch (\Throwable $e) {
            $this->cleanupTempFile($tempFile);
            $this->logger->error('Failed to write file securely', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function deleteFile(string $filename): bool
    {
        if (!$this->exists($filename)) {
            return true; // Already deleted
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

    private function atomicMove(string $source, string $destination): bool
    {
        try {
            if (rename($source, $destination)) {
                chmod($destination, self::FILE_PERMISSIONS);

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to atomically move file', [
                'source' => $source,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);

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
