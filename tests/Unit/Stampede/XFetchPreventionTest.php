<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Stampede;

use KaririCode\Cache\Stampede\{NullPrevention, XFetchPrevention};
use PHPUnit\Framework\TestCase;

final class XFetchPreventionTest extends TestCase
{
    public function testNeverRecomputeWhenNoExpiry(): void
    {
        $xfetch = new XFetchPrevention();

        self::assertFalse($xfetch->shouldRecompute('k', 1000.0, 0.0, 1.0));
    }

    public function testAlwaysRecomputeWhenExpired(): void
    {
        $xfetch = new XFetchPrevention();

        self::assertTrue($xfetch->shouldRecompute('k', 1000.0, 999.0, 1.0));
    }

    public function testNeverRecomputeWithZeroDelta(): void
    {
        $xfetch = new XFetchPrevention();

        self::assertFalse($xfetch->shouldRecompute('k', 1000.0, 2000.0, 0.0));
    }

    public function testProbabilityIncreasesNearExpiry(): void
    {
        $xfetch = new XFetchPrevention(beta: 1.0);
        $iterations = 10_000;

        // Far from expiry: very low probability
        $farRecomputes = 0;
        for ($i = 0; $i < $iterations; ++$i) {
            if ($xfetch->shouldRecompute('k', 1000.0, 2000.0, 0.1)) {
                ++$farRecomputes;
            }
        }

        // Near expiry: high probability
        $nearRecomputes = 0;
        for ($i = 0; $i < $iterations; ++$i) {
            if ($xfetch->shouldRecompute('k', 1999.5, 2000.0, 0.5)) {
                ++$nearRecomputes;
            }
        }

        self::assertGreaterThan($farRecomputes, $nearRecomputes);
    }

    public function testHigherBetaFavorsEarlierRecomputation(): void
    {
        $conservative = new XFetchPrevention(beta: 0.5);
        $aggressive = new XFetchPrevention(beta: 3.0);
        $iterations = 10_000;

        $conservativeCount = 0;
        $aggressiveCount = 0;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($conservative->shouldRecompute('k', 1990.0, 2000.0, 1.0)) {
                ++$conservativeCount;
            }
            if ($aggressive->shouldRecompute('k', 1990.0, 2000.0, 1.0)) {
                ++$aggressiveCount;
            }
        }

        self::assertGreaterThan($conservativeCount, $aggressiveCount);
    }

    public function testNameReturnsXfetch(): void
    {
        self::assertSame('xfetch', (new XFetchPrevention())->name());
    }

    public function testNullPreventionOnlyRecomputesOnExpiry(): void
    {
        $null = new NullPrevention();

        self::assertFalse($null->shouldRecompute('k', 1000.0, 2000.0, 1.0));
        self::assertTrue($null->shouldRecompute('k', 2001.0, 2000.0, 1.0));
        self::assertSame('none', $null->name());
    }
}
