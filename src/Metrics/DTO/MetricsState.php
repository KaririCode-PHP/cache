<?php

declare(strict_types=1);

namespace KaririCode\Cache\Metrics\DTO;

/**
 * A Data Transfer Object to hold the state of the MetricsCollector.
 *
 * Using a dedicated, readonly class instead of a shaped-array improves
 * type-safety, code clarity, and developer experience with auto-completion.
 */
final class MetricsState
{
    public function __construct(
        public readonly int $hits = 0,
        public readonly int $misses = 0,
        public readonly int $generations = 0,
        public readonly int $clears = 0,
        public readonly float $totalHitTime = 0.0,
        public readonly float $totalGenerationTime = 0.0
    ) {
    }
}
