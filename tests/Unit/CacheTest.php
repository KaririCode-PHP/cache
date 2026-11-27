<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Stampede\XFetchPrevention;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        $this->cache = new Cache(
            adapter: new ArrayAdapter(maxItems: 200),
            evictionPolicy: new SievePolicy(capacity: 50),
            stampedePrevention: new XFetchPrevention(),
        );
    }

    public function testGetAndSet(): void
    {
        $this->cache->set('greeting', 'hello');

        self::assertSame('hello', $this->cache->get('greeting'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        self::assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    public function testDelete(): void
    {
        $this->cache->set('temp', 'data');
        $this->cache->delete('temp');

        self::assertNull($this->cache->get('temp'));
    }

    public function testHas(): void
    {
        $this->cache->set('exists', true);

        self::assertTrue($this->cache->has('exists'));
        self::assertFalse($this->cache->has('nope'));
    }

    public function testClear(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->clear();

        self::assertFalse($this->cache->has('a'));
        self::assertFalse($this->cache->has('b'));
    }

    public function testGetMultiple(): void
    {
        $this->cache->set('x', 10);
        $this->cache->set('y', 20);

        $results = $this->cache->getMultiple(['x', 'y', 'z'], 0);

        self::assertSame(10, $results['x']);
        self::assertSame(20, $results['y']);
        self::assertSame(0, $results['z']);
    }

    public function testSetMultiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);

        self::assertSame(1, $this->cache->get('a'));
        self::assertSame(2, $this->cache->get('b'));
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->deleteMultiple(['a', 'b']);

        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
    }

    public function testRememberCachesFactoryResult(): void
    {
        $callCount = 0;

        $factory = function () use (&$callCount): string {
            ++$callCount;

            return 'computed_value';
        };

        $result1 = $this->cache->remember('computed', $factory, 60);
        $result2 = $this->cache->remember('computed', $factory, 60);

        self::assertSame('computed_value', $result1);
        self::assertSame('computed_value', $result2);
        self::assertSame(1, $callCount);
    }

    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidKeyException::class);

        $this->cache->get('invalid{key}');
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(InvalidKeyException::class);

        $this->cache->get('');
    }

    public function testCapacityEviction(): void
    {
        $smallCache = new Cache(
            adapter: new ArrayAdapter(maxItems: 100),
            evictionPolicy: new SievePolicy(capacity: 3),
        );

        $smallCache->set('a', 1);
        $smallCache->set('b', 2);
        $smallCache->set('c', 3);
        $smallCache->set('d', 4); // triggers eviction

        self::assertSame(4, $smallCache->get('d'));

        // At least one of a, b, c should be evicted
        $found = 0;

        foreach (['a', 'b', 'c'] as $key) {
            if (null !== $smallCache->get($key)) {
                ++$found;
            }
        }

        self::assertLessThanOrEqual(2, $found);
    }

    public function testUpdateExistingKeyDoesNotInflateEvictionCount(): void
    {
        $smallCache = new Cache(
            adapter: new ArrayAdapter(maxItems: 100),
            evictionPolicy: new SievePolicy(capacity: 3),
        );

        $smallCache->set('a', 1);
        $smallCache->set('b', 2);
        $smallCache->set('c', 3);

        // Update 'a' — should NOT trigger eviction
        $smallCache->set('a', 'updated');

        self::assertSame('updated', $smallCache->get('a'));
        self::assertSame(2, $smallCache->get('b'));
        self::assertSame(3, $smallCache->get('c'));
    }

    public function testComplexDataTypes(): void
    {
        $array = ['nested' => ['data' => [1, 2, 3]]];
        $this->cache->set('array', $array);
        self::assertSame($array, $this->cache->get('array'));

        $this->cache->set('int', 42);
        self::assertSame(42, $this->cache->get('int'));

        $this->cache->set('float', 3.14);
        self::assertSame(3.14, $this->cache->get('float'));

        $this->cache->set('bool', true);
        self::assertTrue($this->cache->get('bool'));
    }
}
