<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Stampede\LockingPrevention;
use KaririCode\Cache\Stampede\NullPrevention;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\Tests\Fixtures\RecordingEventListener;
use KaririCode\Cache\ValueObject\CacheEntry;
use PHPUnit\Framework\TestCase;

/**
 * TTL, capacity, envelope and stampede semantics (ADR-009, ADR-010).
 */
final class CacheSemanticsTest extends TestCase
{
    public function testZeroOrNegativeTtlDeletesTheEntry(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $cache->set('k', 'v');

        self::assertTrue($cache->set('k', 'new', 0));
        self::assertFalse($cache->has('k'));

        $cache->set('k', 'v');
        self::assertTrue($cache->set('k', 'new', -10));
        self::assertNull($cache->get('k'));
    }

    public function testRememberWithNonPositiveTtlComputesWithoutStoring(): void
    {
        $cache = new Cache(new ArrayAdapter());

        self::assertSame(7, $cache->remember('k', static fn(): int => 7, 0));
        self::assertFalse($cache->has('k'));
    }

    public function testMaxItemsZeroMeansUnbounded(): void
    {
        $cache = new Cache(new ArrayAdapter(maxItems: 0), new SievePolicy(capacity: 0));

        for ($i = 0; $i < 50; ++$i) {
            $cache->set("k{$i}", $i);
        }

        self::assertSame(0, $cache->get('k0'));
        self::assertSame(49, $cache->get('k49'));
    }

    public function testNegativeCapacityIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new SievePolicy(capacity: -1);
    }

    public function testCapacityIsEnforcedThroughThePolicyAndReported(): void
    {
        $listener = new RecordingEventListener();
        $cache = new Cache(new ArrayAdapter(), evictionPolicy: new LruPolicy(capacity: 2), eventListener: $listener);

        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->get('a');
        $cache->set('c', 3);

        self::assertFalse($cache->has('b'));
        self::assertTrue($cache->has('a'));
        self::assertContains('evict:b', $listener->events);
    }

    public function testExpiredEntriesStopOccupyingTrackedCapacity(): void
    {
        $adapter = new ArrayAdapter();
        $cache = new Cache($adapter, evictionPolicy: new LruPolicy(capacity: 2));
        $cache->set('a', 1);
        $cache->set('b', 2);
        $adapter->remove('a');          // expired or evicted by the backend

        self::assertNull($cache->get('a'));
        $cache->set('c', 3);            // must not evict b: a was dropped on the miss

        self::assertTrue($cache->has('b'));
        self::assertTrue($cache->has('c'));
    }

    public function testEnvelopeDistinguishesCachedNullFromMiss(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $cache->set('n', null);

        self::assertNull($cache->get('n', 'default'));
        self::assertSame('default', $cache->get('missing', 'default'));
    }

    public function testEnvelopeIsWhatTheBackendStores(): void
    {
        $adapter = new ArrayAdapter();
        (new Cache($adapter))->remember('k', static function (): string {
            usleep(2000);

            return 'v';
        }, 60);

        $stored = $adapter->read('k');
        self::assertInstanceOf(CacheEntry::class, $stored);
        self::assertGreaterThan(0.0, $stored->delta, 'recompute cost measured with hrtime');
        self::assertNotNull($stored->expiresAt);
    }

    public function testXFetchDecisionUsesMetadataWrittenByAnotherInstance(): void
    {
        // Two Cache instances over one backend stand for two PHP workers.
        $shared = new ArrayAdapter();
        $writer = new Cache($shared);
        $reader = new Cache($shared, stampedePrevention: new XFetchPrevention(beta: 1.0));

        $shared->write('k', new CacheEntry('stale', microtime(true) - 10, microtime(true) + 0.001, 30.0), 60);

        $recomputed = 0;
        for ($i = 0; $i < 20; ++$i) {
            $reader->remember('k', static function () use (&$recomputed): string {
                ++$recomputed;

                return 'fresh';
            }, 60);
        }

        // gap 1 ms, delta 30 s: P(recompute) ≈ 1 on the first call.
        self::assertGreaterThanOrEqual(1, $recomputed);
        self::assertSame('fresh', $writer->get('k'));
    }

    public function testRememberStaleServesStoredValueWhileLockedByAnotherCaller(): void
    {
        $shared = new ArrayAdapter();
        $locking = new LockingPrevention(lockDuration: 30.0);
        $cache = new Cache($shared, stampedePrevention: $locking);

        $shared->write('k', new CacheEntry('stale', microtime(true) - 10, microtime(true) - 1, 0.1), 60);
        $locking->tryLock('k', microtime(true));    // another caller is recomputing

        self::assertSame('stale', $cache->rememberStale('k', static fn(): string => 'fresh', 1, 60));
    }

    public function testRememberStaleRejectsAnInvalidWindow(): void
    {
        $cache = new Cache(new ArrayAdapter());

        $this->expectException(ConfigurationException::class);
        $cache->rememberStale('k', static fn(): int => 1, freshTtl: 60, staleTtl: 10);
    }

    public function testEventsForEveryOutcome(): void
    {
        $listener = new RecordingEventListener();
        $cache = new Cache(new ArrayAdapter(), stampedePrevention: new NullPrevention(), eventListener: $listener);

        $cache->get('k');
        $cache->set('k', 1);
        $cache->get('k');
        $cache->delete('k');

        self::assertSame(['miss:k', 'write:k', 'hit:k', 'delete:k'], $listener->events);
    }

    public function testDeleteIsIdempotent(): void
    {
        $cache = new Cache(new ArrayAdapter());

        self::assertTrue($cache->delete('never-set'));
        self::assertTrue($cache->deleteMultiple(['x', 'y']));
    }

    public function testSetMultipleCastsNumericStringKeys(): void
    {
        $cache = new Cache(new ArrayAdapter());

        self::assertTrue($cache->setMultiple(['1' => 'one', '2' => 'two']));
        self::assertSame('two', $cache->get('2'));
    }
}
