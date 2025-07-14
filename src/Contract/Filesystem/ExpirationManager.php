<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

/**
 * Interface ExpirationManager.
 *
 * Defines the contract for managing the expiration of cache items
 * in a filesystem-based cache.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface ExpirationManager
{
    /**
     * Checks if a cache item has expired based on its metadata.
     *
     * @param array $metadata the item's metadata
     *
     * @return bool true if the item is expired
     */
    public function isExpired(array $metadata): bool;

    /**
     * Creates metadata for a new cache item.
     *
     * @param mixed $value the value being cached
     * @param \DateTimeInterface|null $expiration the expiration timestamp
     *
     * @return array the metadata array
     */
    public function createMetadata(mixed $value, ?\DateTimeInterface $expiration): array;

    /**
     * Determines if a cleanup of expired items should be performed.
     *
     * @return bool true if cleanup is needed
     */
    public function shouldCleanup(): bool;

    /**
     * Updates the access time in the item's metadata.
     *
     * @param array $metadata the metadata to update
     */
    public function updateAccessTime(array &$metadata): void;
}
