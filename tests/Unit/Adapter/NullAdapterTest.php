<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Adapter;

use KaririCode\Cache\Adapter\NullAdapter;
use PHPUnit\Framework\TestCase;

final class NullAdapterTest extends TestCase
{
    private NullAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new NullAdapter();
    }

    public function testReadAlwaysReturnsNull(): void
    {
        $this->adapter->write('key', 'value');

        self::assertNull($this->adapter->read('key'));
    }

    public function testWriteAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->adapter->write('key', 'value'));
    }

    public function testRemoveAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->adapter->remove('key'));
    }

    public function testExistsAlwaysReturnsFalse(): void
    {
        self::assertFalse($this->adapter->exists('key'));
    }

    public function testFlushReturnsTrue(): void
    {
        self::assertTrue($this->adapter->flush());
    }

    public function testReadMultipleReturnsNulls(): void
    {
        $results = $this->adapter->readMultiple(['a', 'b', 'c']);

        self::assertSame(['a' => null, 'b' => null, 'c' => null], $results);
    }

    public function testStatsReturnsZeroCounters(): void
    {
        $stats = $this->adapter->stats();

        self::assertSame(0, $stats->hits);
        self::assertSame(0, $stats->misses);
        self::assertSame('null', $stats->adapterName);
    }

    public function testIsAvailableReturnsTrue(): void
    {
        self::assertTrue($this->adapter->isAvailable());
    }

    public function testNameReturnsNull(): void
    {
        self::assertSame('null', $this->adapter->name());
    }
}
