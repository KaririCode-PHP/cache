<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Contract\ImmutableValue;

/**
 * Defines a standardized contract for cache driver statistics.
 */
interface DriverStats extends ImmutableValue
{
    /**
     * Gets the amount of memory used by the cache.
     *
     * @return int|null memory usage in bytes, or null if not supported
     */
    public function getMemoryUsage(): ?int;

    /**
     * Gets the total number of items in the cache.
     *
     * @return int|null the item count, or null if not supported/efficient
     */
    public function getItemCount(): ?int;

    /**
     * Gets the driver name.
     */
    public function getDriverName(): string;

    /**
     * Gets the driver or backend service version.
     */
    public function getDriverVersion(): ?string;

    /**
     * Gets all raw, driver-specific statistics.
     *
     * @return array<string, mixed>
     */
    public function getRawStats(): array;
}
