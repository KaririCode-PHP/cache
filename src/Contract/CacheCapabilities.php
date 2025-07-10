<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Constants for cache driver capabilities.
 */
interface CacheCapabilities
{
    public const ATOMIC_OPERATIONS = 'atomic';
    public const TAGGING = 'tagging';
    public const TOPICS = 'topics';
    public const TRANSACTIONS = 'transactions';
}
