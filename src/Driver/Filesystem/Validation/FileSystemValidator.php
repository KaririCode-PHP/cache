<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Validation;

use KaririCode\Cache\Contract\Filesystem\FileSystemValidator as FileSystemValidatorContract;
use KaririCode\Cache\Driver\Filesystem\Config\FilesystemConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Filesystem-specific validator that adheres to the Single Responsibility Principle.
 *
 * This class is solely responsible for validating keys and values according to the
 * rules defined in the central FilesystemConfig DTO. It ensures that all data
 * is safe and compatible with a filesystem storage backend.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FileSystemValidator implements FileSystemValidatorContract
{
    /**
     * @param FilesystemConfig $config the configuration DTO for the filesystem driver
     */
    public function __construct(
        private readonly FilesystemConfig $config
    ) {
    }

    /**
     * Validates a cache key against the configured rules.
     *
     * @param string $key the key to validate
     *
     * @throws InvalidArgumentException if the key is invalid
     */
    public function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > $this->config->maxKeyLength) {
            throw InvalidArgumentException::keyTooLong($key, $this->config->maxKeyLength);
        }

        // Check for path traversal attempts
        if (str_contains($key, '..')) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains path traversal characters)');
        }

        // Check for directory separators
        if (str_contains($key, '/') || str_contains($key, '\\')) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains directory separators)');
        }

        // Check for control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains control characters)');
        }
    }

    /**
     * Validates a cache value against the configured rules.
     *
     * @param mixed $value the value to validate
     *
     * @throws InvalidArgumentException if the value is invalid
     */
    public function validateValue(mixed $value): void
    {
        try {
            $serialized = serialize($value);

            if (strlen($serialized) > $this->config->maxValueSize) {
                throw InvalidArgumentException::invalidValue('Value size (' . strlen($serialized) . ' bytes) exceeds the maximum allowed size of ' . $this->config->maxValueSize . ' bytes.');
            }
        } catch (\Exception $e) {
            throw InvalidArgumentException::unserializableValue(get_debug_type($value));
        }
    }

    /**
     * Validates a filesystem path to prevent security issues like path traversal.
     *
     * @param string $path the path to validate
     *
     * @throws InvalidArgumentException if the path is invalid
     */
    public function validatePath(string $path): void
    {
        if (str_contains($path, '..')) {
            throw InvalidArgumentException::invalidValue("Path contains traversal elements: '{$path}'");
        }

        // Ensure path is within the configured base cache directory
        $realBasePath = realpath($this->config->directory);
        $realPath = realpath(dirname($path));

        if (false === $realBasePath || false === $realPath || !str_starts_with($realPath, $realBasePath)) {
            throw InvalidArgumentException::invalidValue("Path is outside the allowed cache directory: '{$path}'");
        }
    }

    /**
     * Sanitizes a cache key for filesystem compatibility.
     *
     * @param string $key the key to sanitize
     *
     * @return string the sanitized key
     */
    public function sanitizeKey(string $key): string
    {
        // Remove problematic characters for filesystem
        $sanitized = (string) preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F\x7F]/', '_', $key);

        // Ensure length limit
        return substr($sanitized, 0, $this->config->maxKeyLength);
    }
}
