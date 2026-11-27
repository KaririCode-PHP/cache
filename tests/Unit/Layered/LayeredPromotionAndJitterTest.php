<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Layered;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Layered\LayeredCache;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use KaririCode\Cache\Tests\Fixtures\TtlRecordingAdapter;
use KaririCode\Cache\ValueObject\CacheEntry;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Promotion never extends a value's life, and layer TTL jitter only
 * shortens; both are exact under a frozen clock and a seeded engine.
 */
final class LayeredPromotionAndJitterTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
    }

    private function entryLiving(float $seconds): CacheEntry
    {
        $now = (float) $this->clock->now()->format('U.u');

        return new CacheEntry(value: 'v', createdAt: $now, expiresAt: $now + $seconds);
    }

    public function testPromotionUsesTheWholeSecondsLeft(): void
    {
        foreach ([[100.5, 100], [1.5, 1], [3.2, 3]] as [$left, $expected]) {
            $fast = new TtlRecordingAdapter();
            $slow = new ArrayAdapter();
            $slow->write('k', $this->entryLiving($left));
            $cache = (new LayeredCache(clock: $this->clock))->addLayer($fast, $expected === 3 ? 5 : null)->addLayer($slow);

            self::assertSame('v', $cache->get('k'));
            self::assertSame([$expected], $fast->ttlsFor('k'), "left {$left}");
        }
    }

    public function testAnEntryWithLessThanASecondLeftIsNotPromoted(): void
    {
        $fast = new TtlRecordingAdapter();
        $slow = new ArrayAdapter();
        $slow->write('k', $this->entryLiving(0.5));
        $cache = (new LayeredCache(clock: $this->clock))->addLayer($fast)->addLayer($slow);

        self::assertSame('v', $cache->get('k'));
        self::assertSame([], $fast->ttlsFor('k'));
    }

    public function testOnlyLayersAboveTheHitAreWritten(): void
    {
        $fast = new TtlRecordingAdapter();
        $slow = new TtlRecordingAdapter();
        $slow->write('k', $this->entryLiving(50.0), 50);
        $cache = (new LayeredCache(clock: $this->clock))->addLayer($fast)->addLayer($slow);

        $cache->get('k');

        self::assertSame([50], $slow->ttlsFor('k'), 'the hit layer is not rewritten');
        self::assertSame([50], $fast->ttlsFor('k'));
    }

    public function testLayerTtlJitterReplaysFromTheSeedAndOnlyShortens(): void
    {
        foreach ([100, 10] as $layerTtl) {
            $fast = new TtlRecordingAdapter();
            $cache = (new LayeredCache(clock: $this->clock, randomizer: new Randomizer(new Mt19937(7))))->addLayer($fast, $layerTtl)->addLayer(new ArrayAdapter());
            $reference = new Randomizer(new Mt19937(7));
            $expected = [];
            $observed = [];

            for ($i = 0; $i < 20; ++$i) {
                $cache->set("k{$i}", $i);
                $expected[] = $layerTtl - $reference->getInt(0, intdiv($layerTtl, 10));
                $observed[] = $fast->ttlsFor("k{$i}")[0];
            }

            self::assertSame($expected, $observed, "layer ttl {$layerTtl}");
            self::assertLessThan($layerTtl * 20, array_sum($observed), 'jitter must shorten some entries');
        }
    }

    public function testExplicitTtlIsNeverJittered(): void
    {
        $fast = new TtlRecordingAdapter();
        $cache = (new LayeredCache(randomizer: new Randomizer(new Mt19937(7))))->addLayer($fast, 100);

        $cache->set('k', 1, 30);

        self::assertSame([30], $fast->ttlsFor('k'));
    }
}
