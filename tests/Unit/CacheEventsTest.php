<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use KaririCode\Cache\Tests\Fixtures\RecordingEventListener;
use PHPUnit\Framework\TestCase;

/**
 * The cache keeps its policy and its listener in step with every operation.
 */
final class CacheEventsTest extends TestCase
{
    private function lruCache(RecordingEventListener $listener): Cache
    {
        return new Cache(new ArrayAdapter(), new LruPolicy(capacity: 2), eventListener: $listener);
    }

    public function testHitsAndMissesAreReported(): void
    {
        $listener = new RecordingEventListener();
        $cache = $this->lruCache($listener);
        $cache->set('a', 1);

        $cache->get('a');
        $cache->get('zz');
        $cache->remember('m', static fn(): int => 1, 60);

        self::assertContains('hit:a', $listener->events);
        self::assertContains('miss:zz', $listener->events);
        self::assertContains('miss:m', $listener->events);
    }

    public function testReadsRefreshRecency(): void
    {
        $listener = new RecordingEventListener();
        $cache = $this->lruCache($listener);
        $cache->set('a', 1);
        $cache->set('b', 2);

        $cache->get('a');
        $cache->set('c', 3);

        self::assertSame(['evict:b'], array_values(array_filter($listener->events, static fn(string $e): bool => str_starts_with($e, 'evict:'))));
        self::assertTrue($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function testDeleteAndClearReleaseCapacity(): void
    {
        $listener = new RecordingEventListener();
        $cache = $this->lruCache($listener);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->delete('a');
        $cache->set('c', 3);

        $cache->clear();
        $cache->set('d', 4);
        $cache->set('e', 5);

        self::assertSame([], array_values(array_filter($listener->events, static fn(string $e): bool => str_starts_with($e, 'evict:'))));
        self::assertTrue($cache->has('d') && $cache->has('e'));
    }

    public function testRecomputeIsAnnouncedWithTheGapToTheLogicalExpiry(): void
    {
        $clock = new FrozenClock();
        $gaps = [];
        $listener = new class ($gaps) implements CacheEventListener {
            /** @param list<float> $gaps */
            public function __construct(private array &$gaps) {}

            public function onHit(string $key): void {}

            public function onMiss(string $key): void {}

            public function onWrite(string $key, ?int $ttl): void {}

            public function onDelete(string $key): void {}

            public function onEviction(string $key, string $policyName): void {}

            public function onStampedeRecompute(string $key, float $gapSeconds): void
            {
                $this->gaps[] = $gapSeconds;
            }
        };
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
        $cache = new Cache(new ArrayAdapter(clock: $clock), stampedePrevention: $always, eventListener: $listener, clock: $clock);

        $cache->remember('k', static fn(): int => 1, 600);
        $clock->advance(100);
        self::assertSame(2, $cache->remember('k', static fn(): int => 2, 600));

        $cache->rememberStale('s', static fn(): int => 1, 10, 100);
        $clock->advance(20);
        $cache->rememberStale('s', static fn(): int => 2, 10, 100);

        self::assertSame([500.0, 0.0], $gaps);
    }

    public function testRememberStaleStoresWhatItComputes(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $calls = 0;
        $factory = static function () use (&$calls): int {
            return ++$calls;
        };

        $cache->rememberStale('s', $factory, 60, 600);
        $cache->rememberStale('s', $factory, 60, 600);

        self::assertSame(1, $calls);
    }
}
