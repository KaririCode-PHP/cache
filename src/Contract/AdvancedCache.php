<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\MultiOperationCacheable;

/**
 * Extended interface for advanced operations.
 */
interface AdvancedCache extends Cache, AtomicOperable, MultiOperationCacheable
{
}
