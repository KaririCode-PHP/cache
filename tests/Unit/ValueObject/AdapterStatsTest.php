<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\ValueObject;

use KaririCode\Cache\ValueObject\AdapterStats;
use PHPUnit\Framework\TestCase;

final class AdapterStatsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $stats = new AdapterStats();

        self::assertSame(0, $stats->hits);
        self::assertSame(0, $stats->misses);
        self::assertSame(0, $stats->writes);
        self::assertSame(0, $stats->deletes);
        self::assertSame(0, $stats->itemCount);
        self::assertSame(0, $stats->memoryBytes);
        self::assertSame('', $stats->adapterName);
    }

    public function testTotalRequestsPropertyHook(): void
    {
        $stats = new AdapterStats(hits: 75, misses: 25);

        self::assertSame(100, $stats->totalRequests());
    }

    public function testHitRatioPropertyHook(): void
    {
        $stats = new AdapterStats(hits: 75, misses: 25);

        self::assertEqualsWithDelta(0.75, $stats->hitRatio(), 0.001);
    }

    public function testHitRatioZeroWhenNoRequests(): void
    {
        $stats = new AdapterStats();

        self::assertSame(0.0, $stats->hitRatio());
    }

    public function testHitRatioPerfectWhenAllHits(): void
    {
        $stats = new AdapterStats(hits: 100, misses: 0);

        self::assertSame(1.0, $stats->hitRatio());
    }

    public function testConstructionWithNamedParameters(): void
    {
        $stats = new AdapterStats(
            hits: 10,
            misses: 5,
            writes: 20,
            deletes: 3,
            itemCount: 42,
            memoryBytes: 1024,
            adapterName: 'redis',
        );

        self::assertSame(10, $stats->hits);
        self::assertSame(42, $stats->itemCount);
        self::assertSame('redis', $stats->adapterName);
    }
}
