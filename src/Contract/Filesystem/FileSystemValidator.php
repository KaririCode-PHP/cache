<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

/**
 * Interface FileSystemValidator.
 *
 * Defines the contract for validating keys, values, and paths
 * in a filesystem-based cache.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface FileSystemValidator
{
    /**
     * Validates a cache key.
     *
     * @param string $key the key to validate
     *
     * @throws \Psr\Cache\InvalidArgumentException if the key is invalid
     */
    public function validateKey(string $key): void;

    /**
     * Validates a cache value.
     *
     * @param mixed $value the value to validate
     */
    public function validateValue(mixed $value): void;

    /**
     * Validates a filesystem path.
     *
     * @param string $path the path to validate
     */
    public function validatePath(string $path): void;

    /**
     * Sanitizes a cache key for filesystem compatibility.
     *
     * @param string $key the key to sanitize
     *
     * @return string the sanitized key
     */
    public function sanitizeKey(string $key): string;
}
