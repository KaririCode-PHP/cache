<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\ValueObject;

use KaririCode\Cache\ValueObject\CacheEntry;
use PHPUnit\Framework\TestCase;

final class CacheEntryTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $entry = new CacheEntry(
            value: 'test',
            createdAt: 1000.0,
            expiresAt: 2000.0,
            delta: 0.5,
            tags: ['user', 'profile'],
        );

        self::assertSame('test', $entry->value);
        self::assertSame(1000.0, $entry->createdAt);
        self::assertSame(2000.0, $entry->expiresAt);
        self::assertSame(0.5, $entry->delta);
        self::assertSame(['user', 'profile'], $entry->tags);
    }

    public function testWithTtlFactorySetsExpiry(): void
    {
        $before = microtime(true);
        $entry = CacheEntry::withTtl('value', 60, microtime(true));
        $after = microtime(true);

        self::assertSame('value', $entry->value);
        self::assertGreaterThanOrEqual($before, $entry->createdAt);
        self::assertLessThanOrEqual($after, $entry->createdAt);
        self::assertNotNull($entry->expiresAt);
        self::assertEqualsWithDelta($entry->createdAt + 60, $entry->expiresAt, 0.1);
    }

    public function testWithTtlNullMeansNoExpiry(): void
    {
        $entry = CacheEntry::withTtl('value', null, microtime(true));

        self::assertNull($entry->expiresAt);
    }

    public function testIsExpiredReturnsFalseForFreshEntry(): void
    {
        $entry = CacheEntry::withTtl('value', 3600, microtime(true));

        self::assertFalse($entry->isExpiredAt(microtime(true)));
    }

    public function testIsExpiredReturnsTrueForPastExpiry(): void
    {
        $entry = new CacheEntry(
            value: 'value',
            createdAt: microtime(true) - 100,
            expiresAt: microtime(true) - 50,
        );

        self::assertTrue($entry->isExpiredAt(microtime(true)));
    }

    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
    {
        $entry = new CacheEntry(value: 'value', createdAt: microtime(true));

        self::assertFalse($entry->isExpiredAt(microtime(true)));
    }

    public function testTtlRemainingForFreshEntry(): void
    {
        $entry = CacheEntry::withTtl('value', 3600, microtime(true));

        self::assertNotNull($entry->secondsLeftAt(microtime(true)));
        self::assertGreaterThan(3599.0, $entry->secondsLeftAt(microtime(true)));
    }

    public function testTtlRemainingNullWhenNoExpiry(): void
    {
        $entry = CacheEntry::withTtl('value', null, microtime(true));

        self::assertNull($entry->secondsLeftAt(microtime(true)));
    }

    public function testTtlRemainingClampedToZeroWhenExpired(): void
    {
        $entry = new CacheEntry(
            value: 'value',
            createdAt: microtime(true) - 100,
            expiresAt: microtime(true) - 50,
        );

        self::assertSame(0.0, $entry->secondsLeftAt(microtime(true)));
    }

    public function testAgePropertyHook(): void
    {
        $entry = new CacheEntry(
            value: 'value',
            createdAt: microtime(true) - 10.0,
        );

        self::assertGreaterThanOrEqual(10.0, $entry->ageAt(microtime(true)));
        self::assertLessThan(11.0, $entry->ageAt(microtime(true)));
    }

    public function testWithTtlPreservesDeltaAndTags(): void
    {
        $entry = new CacheEntry(value: 'value', createdAt: microtime(true), expiresAt: microtime(true) + 60, delta: 0.25, tags: ['a', 'b']);

        self::assertSame(0.25, $entry->delta);
        self::assertSame(['a', 'b'], $entry->tags);
    }
}
