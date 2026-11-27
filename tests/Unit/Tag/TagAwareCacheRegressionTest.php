<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Tag;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Tag\TagAwareCache;
use KaririCode\Cache\ValueObject\TaggedEntry;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for the 4.0.x tag implementation (ADR-012).
 */
final class TagAwareCacheRegressionTest extends TestCase
{
    public function testUserArrayShapedLikeTheOldEnvelopeIsReturnedUntouched(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());
        $value = ['value' => 'x', 'tag_versions' => []];

        $cache->set('arr', $value);

        self::assertSame($value, $cache->get('arr'));
    }

    public function testLosingTheTagVersionInvalidatesInsteadOfResurrecting(): void
    {
        $adapter = new ArrayAdapter();
        $cache = new TagAwareCache($adapter);
        $cache->setTagged('post:1', 'old', ['posts']);
        $cache->invalidateTags(['posts']);

        $adapter->remove('__tag_version:posts');   // evicted or flushed by the backend

        self::assertNull($cache->get('post:1'));
    }

    public function testInvalidationDuringComputationWins(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());

        $cache->remember('report', static function () use ($cache): string {
            $cache->invalidateTags(['sales']);      // data changed while computing

            return 'stale-report';
        }, 60, ['sales']);

        self::assertNull($cache->get('report'), 'versions are captured before the factory runs');
    }

    public function testEveryInvalidationWritesADistinctVersion(): void
    {
        $adapter = new ArrayAdapter();
        $cache = new TagAwareCache($adapter);
        $versions = [];

        for ($i = 0; $i < 20; ++$i) {
            $cache->invalidateTags(['t']);
            $versions[] = $adapter->read('__tag_version:t');
        }

        self::assertCount(20, array_unique($versions));
    }

    public function testCachedNullIsAHit(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());
        $cache->setTagged('n', null, ['t']);

        self::assertTrue($cache->has('n'));
        self::assertNull($cache->get('n', 'default'));

        $cache->invalidateTags(['t']);
        self::assertFalse($cache->has('n'));
        self::assertSame('default', $cache->get('n', 'default'));
    }

    public function testRememberStaleStoresOneEnvelopeWithoutSidecarKeys(): void
    {
        $adapter = new ArrayAdapter();
        $cache = new TagAwareCache($adapter);

        self::assertSame(1, $cache->rememberStale('s', static fn(): int => 1, 10, 60));
        self::assertInstanceOf(TaggedEntry::class, $adapter->read('s'));
        self::assertNull($adapter->read('s:__fresh'));
    }

    public function testInvalidStaleWindowIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new TagAwareCache(new ArrayAdapter()))->rememberStale('s', static fn(): int => 1, 10, 5);
    }

    public function testReservedInternalPrefixIsRejected(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionCode(InvalidKeyException::RESERVED_PREFIX);

        (new TagAwareCache(new ArrayAdapter()))->set('__tag_version:x', 1);
    }

    public function testNumericStringKeysInSetMultiple(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());

        self::assertTrue($cache->setMultiple(['1' => 'one']));
        self::assertSame('one', $cache->get('1'));
    }
}
