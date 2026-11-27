<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Bridge;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Bridge\Psr6Bridge;
use KaririCode\Cache\Bridge\Psr6CacheItem;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Tests\Fixtures\FlakyAdapter;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use KaririCode\Cache\Tests\Fixtures\TtlRecordingAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The PSR bridges apply the PSR key rules on every operation (":" is
 * reserved by PSR but allowed by the native API), convert TTLs exactly and
 * keep PSR-6's deferred-item contract.
 */
final class BridgeContractTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(Psr16Bridge): mixed}>
     */
    public static function psr16Operations(): iterable
    {
        yield 'get' => [static fn(Psr16Bridge $b): mixed => $b->get('a:b')];
        yield 'set' => [static fn(Psr16Bridge $b): mixed => $b->set('a:b', 1)];
        yield 'delete' => [static fn(Psr16Bridge $b): mixed => $b->delete('a:b')];
        yield 'has' => [static fn(Psr16Bridge $b): mixed => $b->has('a:b')];
        yield 'getMultiple' => [static fn(Psr16Bridge $b): mixed => [...$b->getMultiple(['ok', 'a:b'])]];
        yield 'setMultiple' => [static fn(Psr16Bridge $b): mixed => $b->setMultiple(['ok' => 1, 'a:b' => 2])];
        yield 'deleteMultiple' => [static fn(Psr16Bridge $b): mixed => $b->deleteMultiple(['ok', 'a:b'])];
    }

    /**
     * @param \Closure(Psr16Bridge): mixed $operation
     */
    #[DataProvider('psr16Operations')]
    #[Group('security')]
    public function testPsr16AppliesThePsrKeyRulesOnEveryOperation(\Closure $operation): void
    {
        $this->expectException(\Psr\SimpleCache\InvalidArgumentException::class);

        $operation(new Psr16Bridge(new Cache(new ArrayAdapter())));
    }

    public function testPsr16ConvertsADateIntervalToSeconds(): void
    {
        $adapter = new TtlRecordingAdapter();
        $bridge = new Psr16Bridge(new Cache($adapter), clock: new FrozenClock());

        $bridge->set('k', 1, new \DateInterval('PT90S'));

        self::assertSame([90], $adapter->ttlsFor('k'));
    }

    /**
     * @return iterable<string, array{\Closure(Psr6Bridge): mixed}>
     */
    public static function psr6Operations(): iterable
    {
        yield 'getItem' => [static fn(Psr6Bridge $p): mixed => $p->getItem('a:b')];
        yield 'getItems' => [static fn(Psr6Bridge $p): mixed => [...$p->getItems(['ok', 'a:b'])]];
        yield 'hasItem' => [static fn(Psr6Bridge $p): mixed => $p->hasItem('a:b')];
        yield 'deleteItem' => [static fn(Psr6Bridge $p): mixed => $p->deleteItem('a:b')];
        yield 'deleteItems' => [static fn(Psr6Bridge $p): mixed => $p->deleteItems(['ok', 'a:b'])];
        yield 'save' => [static fn(Psr6Bridge $p): mixed => $p->save(new Psr6CacheItem('a:b', 1))];
    }

    /**
     * @param \Closure(Psr6Bridge): mixed $operation
     */
    #[DataProvider('psr6Operations')]
    #[Group('security')]
    public function testPsr6AppliesThePsrKeyRulesOnEveryOperation(\Closure $operation): void
    {
        $this->expectException(\Psr\Cache\InvalidArgumentException::class);

        $operation(new Psr6Bridge(new Cache(new ArrayAdapter())));
    }

    public function testPsr6CachedNullIsAHitAndAbsenceIsAMiss(): void
    {
        $pool = new Psr6Bridge(new Cache(new ArrayAdapter()));
        $pool->save($pool->getItem('n')->set(null));
        $pool->save($pool->getItem('v')->set('value'));

        self::assertTrue($pool->getItem('n')->isHit());
        self::assertNull($pool->getItem('n')->get());
        self::assertTrue($pool->getItem('v')->isHit());
        self::assertFalse($pool->getItem('missing')->isHit());
        self::assertFalse((new Psr6CacheItem('fresh'))->isHit());
    }

    public function testDeferredItemsAreHitsUntilTheyExpire(): void
    {
        $clock = new FrozenClock();
        $pool = new Psr6Bridge(new Cache(new ArrayAdapter(clock: $clock), clock: $clock), clock: $clock);

        self::assertTrue($pool->saveDeferred($pool->getItem('d')->set(1)->expiresAfter(10)));
        self::assertTrue($pool->getItem('d')->isHit());
        $clock->advance(11);
        self::assertFalse($pool->getItem('d')->isHit());
    }

    public function testCommitReportsAPartialFailureAndKeepsTheRest(): void
    {
        foreach ([['fail-a', 'ok-a'], ['ok-b', 'fail-b']] as [$first, $second]) {
            $cache = new Cache(new FlakyAdapter());
            $pool = new Psr6Bridge($cache);
            $pool->saveDeferred($pool->getItem($first)->set(1));
            $pool->saveDeferred($pool->getItem($second)->set(2));

            self::assertFalse($pool->commit());
            self::assertTrue($cache->has(str_starts_with($first, 'ok') ? $first : $second));
        }
    }

    public function testDeferredItemsAreCommittedWhenThePoolIsDestroyed(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $pool = new Psr6Bridge($cache);
        $pool->saveDeferred($pool->getItem('x')->set(1));

        unset($pool);

        self::assertTrue($cache->has('x'));
    }
}
