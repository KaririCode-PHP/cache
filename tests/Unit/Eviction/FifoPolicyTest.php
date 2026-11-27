<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Eviction\FifoPolicy;
use PHPUnit\Framework\TestCase;

final class FifoPolicyTest extends TestCase
{
    private FifoPolicy $fifo;

    protected function setUp(): void
    {
        $this->fifo = new FifoPolicy();
    }

    public function testEvictFromEmptyReturnsEmpty(): void
    {
        $result = $this->fifo->evict();

        self::assertFalse($result->success);
        self::assertNull($result->evictedKey);
    }

    public function testInsertionTracksCount(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('b');

        self::assertSame(2, $this->fifo->count());
    }

    public function testEvictsOldestFirst(): void
    {
        $this->fifo->recordInsertion('first');
        $this->fifo->recordInsertion('second');
        $this->fifo->recordInsertion('third');

        $result = $this->fifo->evict();

        self::assertTrue($result->success);
        self::assertSame('first', $result->evictedKey);
        self::assertSame(2, $this->fifo->count());
    }

    public function testAccessDoesNotAffectOrder(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('b');

        // Access 'a' — FIFO ignores access patterns
        $this->fifo->recordAccess('a');

        $result = $this->fifo->evict();

        self::assertSame('a', $result->evictedKey);
    }

    public function testRemovalUpdatesCount(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('b');
        $this->fifo->recordRemoval('a');

        self::assertSame(1, $this->fifo->count());
    }

    public function testEvictSkipsRemovedKeys(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('b');
        $this->fifo->recordInsertion('c');
        $this->fifo->recordRemoval('a');

        $result = $this->fifo->evict();

        self::assertSame('b', $result->evictedKey);
    }

    public function testDuplicateInsertionIsIdempotent(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('a');

        self::assertSame(1, $this->fifo->count());
    }

    public function testClearResetsState(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('b');
        $this->fifo->clear();

        self::assertSame(0, $this->fifo->count());
        self::assertFalse($this->fifo->evict()->success);
    }

    public function testFullEvictionCycle(): void
    {
        $this->fifo->recordInsertion('a');
        $this->fifo->recordInsertion('b');
        $this->fifo->recordInsertion('c');

        $evicted = [];

        for ($i = 0; $i < 3; ++$i) {
            $result = $this->fifo->evict();
            $evicted[] = $result->evictedKey;
        }

        self::assertSame(['a', 'b', 'c'], $evicted);
        self::assertSame(0, $this->fifo->count());
    }

    public function testNameReturnsFifo(): void
    {
        self::assertSame('fifo', $this->fifo->name());
    }
}
