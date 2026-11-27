<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Layered;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Layered\LayeredCache;
use KaririCode\Cache\Stampede\NullPrevention;
use KaririCode\Cache\Tests\Fixtures\TtlRecordingAdapter;
use KaririCode\Cache\ValueObject\CacheEntry;
use PHPUnit\Framework\TestCase;

final class LayeredCacheTest extends TestCase
{
    private ArrayAdapter $l1;
    private ArrayAdapter $l2;
    private LayeredCache $cache;

    protected function setUp(): void
    {
        $this->l1 = new ArrayAdapter();
        $this->l2 = new ArrayAdapter();
        $this->cache = (new LayeredCache())->addLayer($this->l1, 60)->addLayer($this->l2, 3600);
    }

    public function testWriteThroughStoresTheSameEnvelopeInEveryLayer(): void
    {
        $this->cache->set('key', 'value');

        self::assertSame('value', self::entry($this->l1, 'key')->value);
        self::assertSame('value', self::entry($this->l2, 'key')->value);
        self::assertSame(2, $this->cache->layerCount());
    }

    public function testHitInSlowLayerIsPromoted(): void
    {
        $this->l2->write('slow', CacheEntry::withTtl('l2_value', null, microtime(true)));

        self::assertSame('l2_value', $this->cache->get('slow'));
        self::assertSame('l2_value', self::entry($this->l1, 'slow')->value);
    }

    public function testRawValuesWrittenByOtherClientsAreReturnedAndPromoted(): void
    {
        $this->l2->write('raw', 'plain');

        self::assertSame('plain', $this->cache->get('raw'));
        self::assertSame('plain', $this->l1->read('raw'));
    }

    public function testPromotionNeverExtendsTheRemainingLifetime(): void
    {
        $fast = new TtlRecordingAdapter();
        $slow = new ArrayAdapter();
        $cache = (new LayeredCache())->addLayer($fast, 3600)->addLayer($slow, 3600);
        $slow->write('k', CacheEntry::withTtl('v', 5, microtime(true)), 5);

        self::assertSame('v', $cache->get('k'));

        // The fast layer's own TTL is 3600 s; the copy must not outlive the
        // 5 s left on the entry (4.0 wrote it with the layer TTL).
        $ttls = $fast->ttlsFor('k');
        self::assertCount(1, $ttls);
        self::assertNotNull($ttls[0]);
        self::assertGreaterThanOrEqual(1, $ttls[0]);
        self::assertLessThanOrEqual(5, $ttls[0]);
    }

    public function testExpiredLogicalEntryIsNotPromoted(): void
    {
        $fast = new TtlRecordingAdapter();
        $slow = new ArrayAdapter();
        $cache = (new LayeredCache())->addLayer($fast, 60)->addLayer($slow);
        // Stale-window entry: logically expired, still physically present.
        $slow->write('k', new CacheEntry('stale', microtime(true) - 10, microtime(true) - 1), 60);

        $cache->get('k');

        self::assertSame([], $fast->ttlsFor('k'));
    }

    public function testExplicitTtlIsWrittenUnchangedToEveryLayer(): void
    {
        $fast = new TtlRecordingAdapter();
        $slow = new TtlRecordingAdapter();
        $cache = (new LayeredCache())->addLayer($fast, 60)->addLayer($slow, 3600);

        for ($i = 0; $i < 50; ++$i) {
            $cache->set("t{$i}", 'v', 100);
            self::assertSame([100], $fast->ttlsFor("t{$i}"));
            self::assertSame([100], $slow->ttlsFor("t{$i}"));
        }
    }

    public function testLayerTtlJitterOnlyShortens(): void
    {
        $fast = new TtlRecordingAdapter();
        $cache = (new LayeredCache())->addLayer($fast, 1000);

        for ($i = 0; $i < 200; ++$i) {
            $cache->set("j{$i}", 'v');
            $ttl = $fast->ttlsFor("j{$i}")[0];
            self::assertNotNull($ttl);
            self::assertGreaterThanOrEqual(900, $ttl);
            self::assertLessThanOrEqual(1000, $ttl);
        }
    }

    public function testCachedNullIsAHitInsteadOfTheDefault(): void
    {
        $this->cache->set('n', null);

        self::assertNull($this->cache->get('n', 'default'));
        self::assertTrue($this->cache->has('n'));
    }

    public function testDeleteClearAndZeroTtl(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->delete('a');
        self::assertNull($this->l1->read('a'));
        self::assertNull($this->l2->read('a'));

        $this->cache->set('b', 3, 0);
        self::assertFalse($this->cache->has('b'));

        $this->cache->set('c', 3);
        $this->cache->clear();
        self::assertFalse($this->cache->has('c'));
    }

    public function testRememberComputesOnceAndStoresInAllLayers(): void
    {
        $calls = 0;
        $factory = static function () use (&$calls): int {
            ++$calls;

            return 42;
        };

        self::assertSame(42, $this->cache->remember('computed', $factory, 60));
        self::assertSame(42, $this->cache->remember('computed', $factory, 60));
        self::assertSame(1, $calls);
        self::assertSame(42, self::entry($this->l2, 'computed')->value);
    }

    public function testRememberStaleUsesTheFreshWindowAsLogicalExpiry(): void
    {
        $cache = (new LayeredCache(new NullPrevention()))->addLayer($this->l1);

        $cache->rememberStale('s', static fn(): int => 1, 2, 60);

        $entry = self::entry($this->l1, 's');
        self::assertNotNull($entry->expiresAt);
        self::assertLessThanOrEqual(2.01, $entry->expiresAt - microtime(true));
    }

    public function testRememberStaleRecomputesWhenTheStrategySaysSo(): void
    {
        $always = new class implements StampedePrevention {
            public function shouldRecompute(string $key, float $currentTime, float $expiryTime, float $delta): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'always';
            }
        };
        $cache = (new LayeredCache($always))->addLayer($this->l1);
        $calls = 0;
        $factory = static function () use (&$calls): int {
            return ++$calls;
        };

        $cache->rememberStale('s', $factory, 1, 60);
        self::assertSame(2, $cache->rememberStale('s', $factory, 1, 60));
    }

    public function testInvalidStaleWindowIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->cache->rememberStale('s', static fn(): int => 1, 60, 10);
    }

    public function testInvalidLayerTtlIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new LayeredCache())->addLayer(new ArrayAdapter(), 0);
    }

    public function testNumericStringKeysInSetMultiple(): void
    {
        self::assertTrue($this->cache->setMultiple(['1' => 'one', 'two' => 2]));
        self::assertSame(['1' => 'one', 'two' => 2], $this->cache->getMultiple(['1', 'two']));
    }

    private static function entry(ArrayAdapter $adapter, string $key): CacheEntry
    {
        $stored = $adapter->read($key);
        self::assertInstanceOf(CacheEntry::class, $stored);

        return $stored;
    }
}
