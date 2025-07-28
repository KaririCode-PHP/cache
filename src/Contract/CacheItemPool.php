<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Interface CacheItemPool.
 *
 * Extends the PSR-6 CacheItemPoolInterface to provide advanced operations. This interface
 * serves as the framework-interoperable entry point for cache interactions, operating on
 * CacheItem objects rather than direct values. It combines the standardization of PSR-6
 * with the rich features of the KaririCode component, such as atomic operations and
 * invalidation by tags or topics.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheItemPool extends CacheItemPoolInterface, AtomicOperable
{
    // This interface intentionally has an empty body.
    // All required methods are inherited from the parent interfaces:
    // - `Psr\Cache\CacheItemPoolInterface` (for PSR-6 methods like getItem, save, etc.)
    // - `KaririCode\Cache\Contract\Behavioral\AtomicOperable` (for increment, add, etc.)
}
