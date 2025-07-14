<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface CacheCapabilities.
 *
 * Defines constants for representing the capabilities of a cache driver.
 * These constants are used to check if a driver supports specific features
 * like atomic operations, tagging, or transactions.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheCapabilities
{
    public const ATOMIC_OPERATIONS = 'atomic';
    public const TAGGING = 'tagging';
    public const TOPICS = 'topics';
    public const TRANSACTIONS = 'transactions';
}
