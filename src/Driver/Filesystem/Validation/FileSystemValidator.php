<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Validation;

use KaririCode\Cache\Contract\Filesystem\FileSystemValidator as FileSystemValidatorContract;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Filesystem-specific validator following SRP.
 * Responsible ONLY for validation logic.
 */
final class FileSystemValidator implements FileSystemValidatorContract
{
    private const MAX_KEY_LENGTH = 200;
    private const MAX_VALUE_SIZE = 100 * 1024 * 1024; // 100MB

    public function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }

        // Check for path traversal attempts
        if (preg_match('/\.\.\/|\.\.\\\\/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains path traversal)');
        }

        // Check for directory separators
        if (false !== strpos($key, '/') || false !== strpos($key, '\\')) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains directory separators)');
        }

        // Check for control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains control characters)');
        }
    }

    public function validateValue(mixed $value): void
    {
        try {
            $serialized = serialize($value);

            if (strlen($serialized) > self::MAX_VALUE_SIZE) {
                throw InvalidArgumentException::invalidKeyFormat('Value too large: ' . strlen($serialized) . ' bytes (max: ' . self::MAX_VALUE_SIZE . ')');
            }
        } catch (\Exception $e) {
            throw InvalidArgumentException::invalidKeyFormat('Value cannot be serialized: ' . $e->getMessage());
        }
    }

    public function validatePath(string $path): void
    {
        // Ensure path doesn't contain dangerous elements
        if (preg_match('/\.\.\/|\.\.\\\\/', $path)) {
            throw InvalidArgumentException::invalidKeyFormat('Path contains traversal elements');
        }

        // Ensure path is within allowed boundaries
        $realPath = realpath(dirname($path));
        if (false === $realPath) {
            throw InvalidArgumentException::invalidKeyFormat('Invalid path structure');
        }
    }

    public function sanitizeKey(string $key): string
    {
        // Remove problematic characters for filesystem
        $sanitized = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F\x7F]/', '_', $key);

        // Ensure length limit
        return substr($sanitized, 0, self::MAX_KEY_LENGTH);
    }
}
