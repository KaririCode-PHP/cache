<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\MultiOperationCacheable;
use KaririCode\Cache\Metrics\CacheMetrics;

/**
 * Extended interface for advanced operations.
 */
interface AdvancedCache extends Cache, AtomicOperable, MultiOperationCacheable
{
    public function getName(): string;

    public function isHealthy(): bool;

    public function getMetrics(): CacheMetrics;

    public function getCapabilities(): array;
}
