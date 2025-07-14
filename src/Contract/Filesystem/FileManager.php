<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

/**
 * Interface FileManager.
 *
 * Defines the contract for secure file operations within a filesystem-based cache.
 * It abstracts the low-level details of reading, writing, and deleting cache files,
 * often including logic for file locking to prevent race conditions during access.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface FileManager
{
    /**
     * Reads the content of a file securely, often using a shared lock.
     *
     * @param string $filename the full path to the file
     *
     * @return string|null the file content or null on failure or if the file doesn't exist
     */
    public function readSecurely(string $filename): ?string;

    /**
     * Writes data to a file securely, using an exclusive lock to prevent partial reads from other processes.
     *
     * @param string $filename the full path to the file
     * @param string $data the data to write
     *
     * @return bool true on success, false on failure
     */
    public function writeSecurely(string $filename, string $data): bool;

    /**
     * Deletes a file from the filesystem.
     *
     * @param string $filename the full path to the file
     *
     * @return bool true on success or if the file did not exist, false on failure
     */
    public function deleteFile(string $filename): bool;

    /**
     * Checks if a file exists.
     *
     * @param string $filename the full path to the file
     *
     * @return bool true if the file exists
     */
    public function exists(string $filename): bool;

    /**
     * Gets the size of a file.
     *
     * @param string $filename the full path to the file
     *
     * @return int the file size in bytes, or 0 on failure
     */
    public function getSize(string $filename): int;

    /**
     * Gets the last modification time of a file.
     *
     * @param string $filename the full path to the file
     *
     * @return int the modification time as a Unix timestamp, or 0 on failure
     */
    public function getModificationTime(string $filename): int;
}
