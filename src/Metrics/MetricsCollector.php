<?php

declare(strict_types=1);

namespace KaririCode\Cache\Metrics;

use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Metrics\DTO\MetricsState;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable metrics collector that uses a DTO for state management.
 *
 * Each record operation returns a new MetricsCollector instance with an
 * updated state, ensuring thread-safety and a predictable, functional approach.
 */
final class MetricsCollector implements MetricsCollectorContract, ImmutableValue
{
    private readonly MetricsState $state;
    private readonly float $startTime;

    /**
     * Initializes the collector with an optional initial state.
     */
    public function __construct(?MetricsState $state = null)
    {
        $this->state = $state ?? new MetricsState();
        $this->startTime = microtime(true);
    }

    /**
     * Records a cache hit and returns a new collector instance.
     */
    public function recordHit(string $key, float $duration): self
    {
        $newState = new MetricsState(
            hits: $this->state->hits + 1,
            misses: $this->state->misses,
            generations: $this->state->generations,
            clears: $this->state->clears,
            totalHitTime: $this->state->totalHitTime + $duration,
            totalGenerationTime: $this->state->totalGenerationTime
        );

        return new self($newState);
    }

    /**
     * Records a cache miss and returns a new collector instance.
     */
    public function recordMiss(string $key): self
    {
        $newState = new MetricsState(
            hits: $this->state->hits,
            misses: $this->state->misses + 1,
            generations: $this->state->generations,
            clears: $this->state->clears,
            totalHitTime: $this->state->totalHitTime,
            totalGenerationTime: $this->state->totalGenerationTime
        );

        return new self($newState);
    }

    /**
     * Records a value generation and returns a new collector instance.
     */
    public function recordGeneration(string $key, float $duration): self
    {
        $newState = new MetricsState(
            hits: $this->state->hits,
            misses: $this->state->misses,
            generations: $this->state->generations + 1,
            clears: $this->state->clears,
            totalHitTime: $this->state->totalHitTime,
            totalGenerationTime: $this->state->totalGenerationTime + $duration
        );

        return new self($newState);
    }

    /**
     * Records a cache clear operation and returns a new collector instance.
     */
    public function recordClear(): self
    {
        $newState = new MetricsState(
            hits: $this->state->hits,
            misses: $this->state->misses,
            generations: $this->state->generations,
            clears: $this->state->clears + 1,
            totalHitTime: $this->state->totalHitTime,
            totalGenerationTime: $this->state->totalGenerationTime
        );

        return new self($newState);
    }

    /**
     * Gets a snapshot of the current metrics.
     *
     * @return array{
     * hits: int,
     * misses: int,
     * hit_rate: float,
     * total_operations: int,
     * average_hit_time_ms: float,
     * average_generation_time_ms: float,
     * uptime_seconds: float
     * }
     */
    public function getMetrics(): array
    {
        $hits = $this->state->hits;
        $misses = $this->state->misses;
        $totalLookups = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => $totalLookups > 0 ? round($hits / $totalLookups, 4) : 0.0,
            'total_operations' => $totalLookups,
            'average_hit_time_ms' => $hits > 0 ? round(($this->state->totalHitTime / $hits) * 1000, 4) : 0.0,
            'average_generation_time_ms' => $this->state->generations > 0 ? round(($this->state->totalGenerationTime / $this->state->generations) * 1000, 4) : 0.0,
            'uptime_seconds' => microtime(true) - $this->startTime,
        ];
    }

    /**
     * Returns a new, empty collector instance.
     */
    public function reset(): self
    {
        return new self();
    }
}
