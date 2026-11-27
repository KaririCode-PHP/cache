<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Bridge;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Bridge\Psr6Bridge;
use KaririCode\Cache\Bridge\Psr6CacheItem;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class Psr6BridgeTest extends TestCase
{
    public function testItemTtlResolution(): void
    {
        $clock = new FrozenClock();
        $item = new Psr6CacheItem(key: 'k', clock: $clock);
        $now = $clock->now();

        self::assertNull($item->resolveTtl($now));
        self::assertSame(30, $item->expiresAfter(30)->resolveTtl($now));
        self::assertSame(90, $item->expiresAfter(new \DateInterval('PT90S'))->resolveTtl($now));
        self::assertNull($item->expiresAfter(null)->resolveTtl($now));
        self::assertSame(60, $item->expiresAt($now->modify('+60 seconds'))->resolveTtl($now));
    }

    public function testExpiresAfterCountsFromTheCallNotFromTheSave(): void
    {
        $clock = new FrozenClock();
        $item = (new Psr6CacheItem(key: 'k', clock: $clock))->expiresAfter(30);

        $clock->advance(10);

        // PSR-6: "the period of time from the present"; a later save keeps the deadline.
        self::assertSame(20, $item->resolveTtl($clock->now()));
    }

    public function testSaveUsesTheItemTtl(): void
    {
        $adapter = new ArrayAdapter();
        $pool = new Psr6Bridge(new Cache($adapter));

        $pool->save($pool->getItem('k')->set('v')->expiresAfter(60));

        $stored = $adapter->read('k');
        self::assertInstanceOf(\KaririCode\Cache\ValueObject\CacheEntry::class, $stored);
        self::assertNotNull($stored->expiresAt);
        self::assertEqualsWithDelta(microtime(true) + 60, $stored->expiresAt, 1.0);
    }

    public function testCommitReportsAndClearsTheQueue(): void
    {
        $pool = new Psr6Bridge(new Cache(new ArrayAdapter()));
        $pool->saveDeferred($pool->getItem('a')->set(1));
        $pool->saveDeferred($pool->getItem('b')->set(2));

        self::assertTrue($pool->commit());
        self::assertTrue($pool->commit(), 'empty queue');
        self::assertSame(2, $pool->getItem('b')->get());
    }
}
