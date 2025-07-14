<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Contract\ImmutableValue;

/**
 * CacheStatistics - Must implement ImmutableValue.
 *
 * Required by:
 * - Cache::getStats(): ImmutableValue
 */
final class CacheStatistics implements ImmutableValue
{
    public function __construct(
        private readonly array $stats
    ) {
    }

    public function getHits(): int
    {
        return $this->stats['hits'] ?? 0;
    }

    public function getMisses(): int
    {
        return $this->stats['misses'] ?? 0;
    }

    public function getHitRate(): float
    {
        return $this->stats['hit_rate'] ?? 0.0;
    }

    public function getMemoryUsage(): int
    {
        return $this->stats['memory_usage'] ?? 0;
    }

    public function getItemCount(): int
    {
        return $this->stats['item_count'] ?? 0;
    }

    public function getUptime(): float
    {
        return $this->stats['uptime'] ?? 0.0;
    }

    public function getDriverStats(): array
    {
        return $this->stats['driver_stats'] ?? [];
    }

    public function toArray(): array
    {
        return $this->stats;
    }
}
