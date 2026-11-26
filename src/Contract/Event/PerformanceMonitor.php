<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Event;

/**
 * Contract for performance monitoring.
 */
interface PerformanceMonitor
{
    public function checkPerformance(float $duration, string $operation, string $key): ?array;

    public function isSlowOperation(float $duration, string $operation): bool;
}
