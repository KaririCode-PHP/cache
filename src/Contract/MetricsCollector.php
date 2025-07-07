<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Metrics collection interface.
 */
interface MetricsCollector
{
    public function recordHit(string $key, float $duration): void;

    public function recordMiss(string $key): void;

    public function recordGeneration(string $key, float $duration): void;

    public function recordClear(): void;

    public function getMetrics(): array;

    public function reset(): void;
}
