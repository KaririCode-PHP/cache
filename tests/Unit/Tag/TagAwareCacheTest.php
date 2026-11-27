<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Tag;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Tag\TagAwareCache;
use PHPUnit\Framework\TestCase;

final class TagAwareCacheTest extends TestCase
{
    private TagAwareCache $cache;

    protected function setUp(): void
    {
        $this->cache = new TagAwareCache(new ArrayAdapter(maxItems: 100));
    }

    public function testSetTaggedAndGetReturnsValue(): void
    {
        $this->cache->setTagged('user.42.profile', ['name' => 'Walmir'], ['user.42'], 60);

        self::assertSame(['name' => 'Walmir'], $this->cache->get('user.42.profile'));
    }

    public function testInvalidateTagCausesReadMiss(): void
    {
        $this->cache->setTagged('user.42.profile', 'data', ['user.42']);
        $this->cache->setTagged('user.42.posts', 'posts', ['user.42']);

        $this->cache->invalidateTags(['user.42']);

        self::assertNull($this->cache->get('user.42.profile'));
        self::assertNull($this->cache->get('user.42.posts'));
    }

    public function testUntaggedEntrySurvivesTagInvalidation(): void
    {
        $this->cache->set('global.config', 'value');
        $this->cache->setTagged('user.1', 'data', ['users']);

        $this->cache->invalidateTags(['users']);

        self::assertSame('value', $this->cache->get('global.config'));
        self::assertNull($this->cache->get('user.1'));
    }

    public function testMultipleTagsAllMustBeValid(): void
    {
        $this->cache->setTagged('key', 'value', ['tag_a', 'tag_b']);

        self::assertSame('value', $this->cache->get('key'));

        // Invalidate only tag_a
        $this->cache->invalidateTags(['tag_a']);

        self::assertNull($this->cache->get('key'));
    }

    public function testRememberWithTags(): void
    {
        $called = 0;

        $value = $this->cache->remember('computed', function () use (&$called) {
            ++$called;
            return 'result';
        }, 60, ['compute_tag']);

        self::assertSame('result', $value);
        self::assertSame(1, $called);

        // Second call returns cached
        $this->cache->remember('computed', function () use (&$called) {
            ++$called;
            return 'new_result';
        }, 60, ['compute_tag']);

        self::assertSame(1, $called);

        // Invalidate tag → forces recompute
        $this->cache->invalidateTags(['compute_tag']);

        $this->cache->remember('computed', function () use (&$called) {
            ++$called;
            return 'recomputed';
        }, 60, ['compute_tag']);

        self::assertSame(2, $called);
    }

    public function testClearRemovesEverything(): void
    {
        $this->cache->setTagged('a', 1, ['tag1']);
        $this->cache->set('b', 2);
        $this->cache->clear();

        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
    }
}
