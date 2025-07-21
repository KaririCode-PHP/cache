<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

/**
 * Interface DirectoryManager.
 *
 * Defines the contract for managing cache directories in a filesystem-based cache.
 * Responsibilities include creating, clearing, and checking the status of directories.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface DirectoryManager
{
    /**
     * Ensures that the specified directory exists.
     *
     * @param string $directory the path to the directory
     *
     * @return bool true if the directory exists or was created successfully
     */
    public function ensureExists(string $directory): bool;

    /**
     * Clears all files and subdirectories from the cache directory.
     *
     * @return bool true on success, false on failure
     */
    public function clear(): bool;

    /**
     * Gets the total size of the cache directory.
     *
     * @return int the size in bytes
     */
    public function getSize(): int;

    /**
     * Gets the total number of files in the cache directory.
     *
     * @return int the file count
     */
    public function getFileCount(): int;

    /**
     * Checks if a directory is writable.
     *
     * @param string $directory the path to the directory
     *
     * @return bool true if the directory is writable
     */
    public function isWritable(string $directory): bool;

    /* Creates a subdirectory path for a given cache key.
     *
     * @param string $key the cache key
     * @param int $levels The number of directory levels to create
     *
     * @return string the full path for the cache file
     */
    public function createSubdirectoryPath(string $key, int $levels = 2): string;

    /**
     * Gets a list of all cache files.
     *
     * @return array<string> an array of file paths
     */
    public function getCacheFiles(): array;

    /**
     * Gets the base cache directory path.
     */
    public function getBaseDirectory(): string;
}
