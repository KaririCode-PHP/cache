<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Fixtures;

use Psr\Clock\ClockInterface;

/**
 * Test double: a PSR-20 clock that only moves when the test advances it.
 */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $at = '2026-01-01 00:00:00.000000')
    {
        $this->now = new \DateTimeImmutable($at);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d microseconds', (int) round($seconds * 1_000_000)));
    }
}
