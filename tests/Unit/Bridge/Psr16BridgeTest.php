<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Bridge;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Cache;
use PHPUnit\Framework\TestCase;

final class Psr16BridgeTest extends TestCase
{
    private Psr16Bridge $bridge;
    private Cache $cache;

    protected function setUp(): void
    {
        $this->cache = new Cache(new ArrayAdapter(maxItems: 100));
        $this->bridge = new Psr16Bridge($this->cache);
    }

    public function testGetAndSet(): void
    {
        $this->bridge->set('key', 'value');

        self::assertSame('value', $this->bridge->get('key'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        self::assertSame('fallback', $this->bridge->get('missing', 'fallback'));
    }

    public function testDelete(): void
    {
        $this->bridge->set('key', 'value');
        $this->bridge->delete('key');

        self::assertNull($this->bridge->get('key'));
    }

    public function testHas(): void
    {
        $this->bridge->set('key', 'value');

        self::assertTrue($this->bridge->has('key'));
        self::assertFalse($this->bridge->has('missing'));
    }

    public function testClear(): void
    {
        $this->bridge->set('a', 1);
        $this->bridge->set('b', 2);
        $this->bridge->clear();

        self::assertFalse($this->bridge->has('a'));
        self::assertFalse($this->bridge->has('b'));
    }

    public function testGetMultiple(): void
    {
        $this->bridge->set('x', 10);
        $this->bridge->set('y', 20);

        $results = $this->bridge->getMultiple(['x', 'y', 'z'], 0);

        self::assertSame(10, $results['x']);
        self::assertSame(20, $results['y']);
        self::assertSame(0, $results['z']);
    }

    public function testSetMultiple(): void
    {
        $this->bridge->setMultiple(['a' => 1, 'b' => 2]);

        self::assertSame(1, $this->bridge->get('a'));
        self::assertSame(2, $this->bridge->get('b'));
    }

    public function testDeleteMultiple(): void
    {
        $this->bridge->set('a', 1);
        $this->bridge->set('b', 2);
        $this->bridge->deleteMultiple(['a', 'b']);

        self::assertNull($this->bridge->get('a'));
    }

    public function testSetWithDateInterval(): void
    {
        $this->bridge->set('key', 'value', new \DateInterval('PT3600S'));

        self::assertSame('value', $this->bridge->get('key'));
    }

    public function testUnwrapReturnsOriginalCache(): void
    {
        self::assertSame($this->cache, $this->bridge->unwrap());
    }
}
