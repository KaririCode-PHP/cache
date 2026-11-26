<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

/**
 * Filesystem-specific cache exceptions.
 */
final class FilesystemException extends AbstractException
{
    private const CODE_PERMISSION_DENIED = 1267;
    private const CODE_FILE_NOT_READABLE = 1260;
    private const CODE_FILE_NOT_WRITABLE = 1261;
    private const CODE_FILE_DELETION_FAILED = 1263;
    private const CODE_FILE_CORRUPTED = 1268;
    private const CODE_FILE_LOCK_FAILED = 1265;
    private const CODE_DISK_SPACE_INSUFFICIENT = 1266;
    private const CODE_DIRECTORY_CREATION_FAILED = 1262;
    private const CODE_DIRECTORY_NOT_EMPTY = 1269;
    private const CODE_DIRECTORY_NOT_WRITABLE = 1270;
    private const CODE_DIRECTORY_CLEAR_FAILED = 1271;
    private const CODE_ATOMIC_WRITE_FAILED = 1264;

    public static function fileNotReadable(string $filename, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_FILE_NOT_READABLE,
            'FILE_NOT_READABLE',
            "File is not readable: {$filename}",
            $previous
        );
    }

    public static function fileNotWritable(string $filename, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_FILE_NOT_WRITABLE,
            'FILE_NOT_WRITABLE',
            "File is not writable: {$filename}",
            $previous
        );
    }

    public static function directoryClearFailed(string $directory, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_DIRECTORY_CLEAR_FAILED,
            'DIRECTORY_CLEAR_FAILED',
            "Failed to clear directory: {$directory}",
            $previous
        );
    }

    public static function directoryNotWritable(string $directory, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_DIRECTORY_NOT_WRITABLE,
            'DIRECTORY_NOT_WRITABLE',
            "Directory is not writable: {$directory}",
            $previous
        );
    }

    public static function directoryCreationFailed(string $directory, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_DIRECTORY_CREATION_FAILED,
            'DIRECTORY_CREATION_FAILED',
            "Failed to create directory: {$directory}",
            $previous
        );
    }

    public static function fileDeletionFailed(string $filename, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_FILE_DELETION_FAILED,
            'FILE_DELETION_FAILED',
            "Failed to delete file: {$filename}",
            $previous
        );
    }

    public static function atomicWriteFailed(string $filename, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_ATOMIC_WRITE_FAILED,
            'ATOMIC_WRITE_FAILED',
            "Atomic write operation failed for file: {$filename}",
            $previous
        );
    }

    public static function fileLockFailed(string $filename, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_FILE_LOCK_FAILED,
            'FILE_LOCK_FAILED',
            "Failed to acquire file lock: {$filename}",
            $previous
        );
    }

    public static function diskSpaceInsufficient(string $directory, int $required, int $available): static
    {
        return static::createException(
            self::CODE_DISK_SPACE_INSUFFICIENT,
            'DISK_SPACE_INSUFFICIENT',
            "Insufficient disk space in {$directory}. Required: {$required} bytes, Available: {$available} bytes"
        );
    }

    public static function permissionDenied(string $path, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_PERMISSION_DENIED,
            'PERMISSION_DENIED',
            "Permission denied for path: {$path}",
            $previous
        );
    }

    public static function fileCorrupted(string $filename, string $reason = ''): static
    {
        $message = "File is corrupted: {$filename}";
        if ($reason) {
            $message .= " ({$reason})";
        }

        return static::createException(
            self::CODE_FILE_CORRUPTED,
            'FILE_CORRUPTED',
            $message
        );
    }

    public static function directoryNotEmpty(string $directory): static
    {
        return static::createException(
            self::CODE_DIRECTORY_NOT_EMPTY,
            'DIRECTORY_NOT_EMPTY',
            "Directory is not empty: {$directory}"
        );
    }
}
