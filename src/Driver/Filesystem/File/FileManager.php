<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\File;

use KaririCode\Cache\Contract\Filesystem\FileManager as FileManagerContract;
use KaririCode\Cache\Exception\CacheException;
use Psr\Log\LoggerInterface;

/**
 * Thread-safe file manager with atomic operations.
 * This class is responsible only for file I/O operations, following the SRP.
 */
final class FileManager implements FileManagerContract
{
    private const LOCK_TIMEOUT = 30;
    private const FILE_PERMISSIONS = 0644;

    public function __construct(
        private LoggerInterface $logger,
        private bool $useLocking = true
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
            if ($this->useLocking) {
                $wouldBlock = false;
                if (!flock($handle, LOCK_SH | LOCK_NB, $wouldBlock)) {
                    if ($wouldBlock) {
                        throw CacheException::lockTimeout($filename, self::LOCK_TIMEOUT);
                    }

                    return null;
                }
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
        $tempFile = $filename . '.tmp.' . uniqid('', true) . '.pid' . getmypid();
        $handle = $this->openFileForWriting($tempFile);

        if (false === $handle) {
            return false;
        }

        try {
            if ($this->useLocking) {
                $wouldBlock = false;
                if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                    if ($wouldBlock) {
                        throw CacheException::lockTimeout($filename, self::LOCK_TIMEOUT);
                    }

                    return false;
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
            if ($this->useLocking) {
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
                @chmod($destination, self::FILE_PERMISSIONS);

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
