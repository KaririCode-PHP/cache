<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Adapter;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * The memory adapter's documented bound, expiry edge and size report.
 */
final class ArrayAdapterLimitsTest extends TestCase
{
    public function testItHoldsTenThousandEntriesByDefaultAndDropsTheOldest(): void
    {
        $adapter = new ArrayAdapter();

        for ($i = 0; $i <= 10_000; ++$i) {
            $adapter->write("k{$i}", $i);
        }

        self::assertSame(10_000, $adapter->stats()->itemCount);
        self::assertFalse($adapter->exists('k0'));
        self::assertTrue($adapter->exists('k10000'));
        self::assertSame(0, $adapter->stats()->memoryBytes);
        self::assertTrue($adapter->flush());
    }

    public function testAnEntryIsValidAtItsDeadlineAndDroppedWhenReadAfterIt(): void
    {
        $clock = new FrozenClock();
        $adapter = new ArrayAdapter(clock: $clock);
        $adapter->write('k', 'v', 10);

        $clock->advance(10);
        self::assertSame('v', $adapter->read('k'));

        $clock->advance(0.5);
        self::assertNull($adapter->read('k'));
        self::assertSame(0, $adapter->stats()->itemCount, 'reading an expired entry frees its slot');
    }
}
