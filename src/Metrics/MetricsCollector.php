<?php

declare(strict_types=1);

namespace KaririCode\Cache\Metrics;

use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable metrics collector for cache statistics.
 *
 * This class adheres to the ImmutableValue contract, meaning each operation
 * that modifies metrics returns a new instance of the collector with the
 * updated state, ensuring thread-safety and predictable behavior.
 */
final class MetricsCollector implements MetricsCollectorContract, ImmutableValue
{
    /**
     * @var array{
     * hits: int,
     * misses: int,
     * generations: int,
     * clears: int,
     * total_hit_time: float,
     * total_generation_time: float,
     * last_reset: int
     * }
     */
    private readonly array $metrics;

    private readonly float $startTime;

    /**
     * @param array{
     * hits?: int,
     * misses?: int,
     * generations?: int,
     * clears?: int,
     * total_hit_time?: float,
     * total_generation_time?: float,
     * last_reset?: int
     * } $initialMetrics
     */
    public function __construct(array $initialMetrics = [])
    {
        $this->metrics = array_merge([
            'hits' => 0,
            'misses' => 0,
            'generations' => 0,
            'clears' => 0,
            'total_hit_time' => 0.0,
            'total_generation_time' => 0.0,
            'last_reset' => time(),
        ], $initialMetrics);

        $this->startTime = microtime(true);
    }

    public function recordHit(string $key, float $duration): self
    {
        $newMetrics = $this->metrics;
        ++$newMetrics['hits'];
        $newMetrics['total_hit_time'] += $duration;

        return new self($newMetrics);
    }

    public function recordMiss(string $key): self
    {
        $newMetrics = $this->metrics;
        ++$newMetrics['misses'];

        return new self($newMetrics);
    }

    public function recordGeneration(string $key, float $duration): self
    {
        $newMetrics = $this->metrics;
        ++$newMetrics['generations'];
        $newMetrics['total_generation_time'] += $duration;

        return new self($newMetrics);
    }

    public function recordClear(): self
    {
        $newMetrics = $this->metrics;
        ++$newMetrics['clears'];

        return new self($newMetrics);
    }

    public function getMetrics(): array
    {
        $hits = $this->metrics['hits'];
        $misses = $this->metrics['misses'];
        $totalOperations = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => $totalOperations > 0 ? round($hits / $totalOperations, 4) : 0.0,
            'total_operations' => $totalOperations,
            'average_hit_time' => $hits > 0 ? $this->metrics['total_hit_time'] / $hits : 0.0,
            'average_generation_time' => $this->metrics['generations'] > 0 ? $this->metrics['total_generation_time'] / $this->metrics['generations'] : 0.0,
            'uptime' => microtime(true) - $this->startTime,
        ];
    }

    public function reset(): self
    {
        return new self();
    }
}
