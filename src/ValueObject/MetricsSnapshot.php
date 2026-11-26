<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * An immutable Value Object representing standardized driver statistics.
 */
final class MetricsSnapshot implements DriverStats
{
    /**
     * @param string $driverName the name of the driver
     * @param int $memoryUsage memory usage in bytes
     * @param int $itemCount the total number of items
     * @param string $driverVersion the version of the driver or backend service
     * @param array<string, mixed> $rawStats all raw, driver-specific statistics
     */
    public function __construct(
        public readonly string $driverName,
        public readonly int $memoryUsage = 0,
        public readonly int $itemCount = 0,
        public readonly string $driverVersion = '1.0.0',
        public readonly array $rawStats = []
    ) {
        $this->validateDriverName();
    }

    /**
     * Validates that the driver name is not empty.
     *
     * @throws InvalidArgumentException
     */
    private function validateDriverName(): void
    {
        if ('' === trim($this->driverName)) {
            throw InvalidArgumentException::invalidValue('Driver name cannot be empty.');
        }
    }
}
