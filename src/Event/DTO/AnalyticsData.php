<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\DTO;

/**
 * A simple DTO to hold analytics operation counts.
 * Using a dedicated class instead of a shaped-array improves type-safety and clarity.
 */
final class AnalyticsData
{
    public function __construct(
        public int $hits = 0,
        public int $misses = 0,
        public int $sets = 0,
        public int $deletes = 0,
        public int $clears = 0
    ) {
    }

    public function total(): int
    {
        return $this->hits + $this->misses + $this->sets + $this->deletes + $this->clears;
    }
}
