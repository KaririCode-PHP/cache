<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable Value Object representing standardized driver statistics.
 */
final class MetricsSnapshot implements DriverStats, ImmutableValue
{
    public function __construct(
        private readonly string $driverName,
        private readonly ?int $memoryUsage,
        private readonly ?int $itemCount,
        private readonly ?string $driverVersion,
        private readonly array $rawStats
    ) {
    }

    public function getMemoryUsage(): ?int
    {
        return $this->memoryUsage;
    }

    public function getItemCount(): ?int
    {
        return $this->itemCount;
    }

    public function getDriverName(): string
    {
        return $this->driverName;
    }

    public function getDriverVersion(): ?string
    {
        return $this->driverVersion;
    }

    public function getRawStats(): array
    {
        return $this->rawStats;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver_name' => $this->driverName,
            'memory_usage' => $this->memoryUsage,
            'item_count' => $this->itemCount,
            'driver_version' => $this->driverVersion,
            'raw_stats' => $this->rawStats,
        ];
    }
}
