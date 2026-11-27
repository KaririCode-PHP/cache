<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Tag;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Tag\TagAwareCache;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Tag versions are shared by every entry of a tag, come from the injected
 * engine, and only entries with a logical expiry are recomputed early.
 */
final class TagVersionsTest extends TestCase
{
    public function testEntriesSharingATagStayValidUntilItIsInvalidated(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());
        $cache->setTagged('a', 1, ['t']);
        $cache->setTagged('b', 2, ['t']);

        self::assertSame([1, 2], [$cache->get('a'), $cache->get('b')]);

        $cache->invalidateTags(['t']);

        self::assertSame([null, null], [$cache->get('a'), $cache->get('b')]);
    }

    public function testTagVersionsComeFromTheInjectedEngine(): void
    {
        $adapter = new ArrayAdapter();

        (new TagAwareCache($adapter, randomizer: new Randomizer(new Mt19937(11))))->setTagged('k', 1, ['t']);

        self::assertSame((new Randomizer(new Mt19937(11)))->getInt(1, PHP_INT_MAX), $adapter->read('__tag_version:t'));
    }

    public function testOnlyEntriesWithALogicalExpiryAreRecomputedEarly(): void
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
        $cache = new TagAwareCache(new ArrayAdapter(), stampedePrevention: $always);
        $calls = 0;
        $factory = static function () use (&$calls): int {
            return ++$calls;
        };

        $cache->remember('ttl', $factory, 60, ['t']);
        $cache->remember('ttl', $factory, 60, ['t']);
        self::assertSame(2, $calls, 'an entry with a logical expiry is offered for recomputation');

        $calls = 0;
        $cache->remember('forever', $factory, null, ['t']);
        $cache->remember('forever', $factory, null, ['t']);
        self::assertSame(1, $calls, 'an entry without expiry is never recomputed early');
    }

    public function testRememberStaleServesAFreshEntryWithoutRecomputing(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());
        $calls = 0;
        $factory = static function () use (&$calls): int {
            return ++$calls;
        };

        $cache->rememberStale('k', $factory, 60, 600);

        self::assertSame(1, $cache->rememberStale('k', $factory, 60, 600));
        self::assertSame(1, $calls);
    }
}
