<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * Defines the contract for cache components that support explicit maintenance operations.
 *
 * This is typically used for caches that require manual cleanup, such as a
 * filesystem-based cache that needs to purge expired files.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface Maintainable
{
    /**
     * Performs maintenance tasks on the cache component.
     *
     * @param array<string, mixed> $options driver-specific options for the maintenance task
     *
     * @return bool returns true on success or if no maintenance is needed, false on failure
     */
    public function maintenance(array $options = []): bool;
}
