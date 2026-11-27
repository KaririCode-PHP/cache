<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Clock;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\Tag\TagAwareCache;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use KaririCode\Cache\ValueObject\TaggedEntry;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Spec V4.3.3 §19.2: time and randomness are injected, so these behaviours
 * are tested without sleeping and reproduced from a seed.
 */
final class InjectedTimeAndRandomnessTest extends TestCase
{
    public function testEntriesExpireOnTheInjectedClockWithoutSleeping(): void
    {
        $clock = new FrozenClock();
        $cache = new Cache(new ArrayAdapter(clock: $clock), clock: $clock);
        $cache->set('k', 'v', 60);

        $clock->advance(59.9);
        self::assertSame('v', $cache->get('k'));

        $clock->advance(0.2);
        self::assertNull($cache->get('k'));
    }

    public function testXFetchDecisionsReplayFromASeed(): void
    {
        $decide = static function (int $seed): array {
            $xfetch = new XFetchPrevention(beta: 1.0, randomizer: new Randomizer(new Mt19937($seed)));
            $decisions = [];

            for ($i = 0; $i < 200; ++$i) {
                $decisions[] = $xfetch->shouldRecompute(key: 'k', currentTime: 1_000.0, expiryTime: 1_001.0, delta: 1.0);
            }

            return $decisions;
        };

        self::assertSame($decide(42), $decide(42));
        self::assertNotSame($decide(42), $decide(43));
        // P(recompute) = exp(-gap / (delta * beta)) = exp(-1) ≈ 0.37
        self::assertEqualsWithDelta(0.37, array_sum(array_map(intval(...), $decide(42))) / 200, 0.1);
    }

    public function testTagVersionsReplayFromASeed(): void
    {
        $versionOf = static function (int $seed): int {
            $adapter = new ArrayAdapter();
            $cache = new TagAwareCache($adapter, randomizer: new Randomizer(new Mt19937($seed)));
            $cache->setTagged('k', 'v', ['user:42']);
            $entry = $adapter->read('k');
            self::assertInstanceOf(TaggedEntry::class, $entry);

            return $entry->tagVersions['user:42'];
        };

        self::assertSame($versionOf(7), $versionOf(7));
        self::assertNotSame($versionOf(7), $versionOf(8));
    }

    public function testEnvelopeTimestampsComeFromTheClock(): void
    {
        $clock = new FrozenClock('@1767225600');
        $adapter = new ArrayAdapter(clock: $clock);
        (new Cache($adapter, new SievePolicy(), clock: $clock))->remember('k', static fn(): int => 1, 600);

        $entry = $adapter->read('k');
        self::assertIsObject($entry);
        self::assertSame(1767225600.0, $entry->createdAt);
        self::assertSame(1767226200.0, $entry->expiresAt);
    }

    public function testSystemClockIsTheDefault(): void
    {
        $before = microtime(true);
        $now = (float) (new SystemClock())->now()->format('U.u');

        self::assertGreaterThanOrEqual($before - 0.001, $now);
        self::assertLessThanOrEqual(microtime(true) + 0.001, $now);
    }
}
