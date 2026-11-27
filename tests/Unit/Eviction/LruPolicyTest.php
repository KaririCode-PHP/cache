<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Eviction\LruPolicy;
use PHPUnit\Framework\TestCase;

final class LruPolicyTest extends TestCase
{
    private LruPolicy $lru;

    protected function setUp(): void
    {
        $this->lru = new LruPolicy();
    }

    public function testEvictFromEmptyReturnsEmpty(): void
    {
        self::assertFalse($this->lru->evict()->success);
    }

    public function testEvictsLeastRecentlyUsed(): void
    {
        $this->lru->recordInsertion('a');
        $this->lru->recordInsertion('b');
        $this->lru->recordInsertion('c');

        // 'a' is oldest (tail), evict it
        $result = $this->lru->evict();

        self::assertSame('a', $result->evictedKey);
    }

    public function testAccessMovesToHead(): void
    {
        $this->lru->recordInsertion('a');
        $this->lru->recordInsertion('b');
        $this->lru->recordInsertion('c');

        // Access 'a' → moves to head, 'b' becomes tail
        $this->lru->recordAccess('a');

        $result = $this->lru->evict();

        self::assertSame('b', $result->evictedKey);
    }

    public function testReinsertionMovesToHead(): void
    {
        $this->lru->recordInsertion('a');
        $this->lru->recordInsertion('b');

        $this->lru->recordInsertion('a');

        $result = $this->lru->evict();

        self::assertSame('b', $result->evictedKey);
    }

    public function testRemovalUpdatesCount(): void
    {
        $this->lru->recordInsertion('a');
        $this->lru->recordInsertion('b');
        $this->lru->recordRemoval('a');

        self::assertSame(1, $this->lru->count());
    }

    public function testClearResetsState(): void
    {
        $this->lru->recordInsertion('a');
        $this->lru->clear();

        self::assertSame(0, $this->lru->count());
        self::assertFalse($this->lru->evict()->success);
    }

    public function testFullEvictionCycle(): void
    {
        $this->lru->recordInsertion('a');
        $this->lru->recordInsertion('b');
        $this->lru->recordInsertion('c');

        $evicted = [];

        for ($i = 0; $i < 3; ++$i) {
            $result = $this->lru->evict();
            $evicted[] = $result->evictedKey;
        }

        self::assertSame(['a', 'b', 'c'], $evicted);
    }

    public function testAccessNonexistentKeyIsNoop(): void
    {
        $this->lru->recordAccess('nonexistent');

        self::assertSame(0, $this->lru->count());
    }

    public function testRemoveNonexistentKeyIsNoop(): void
    {
        $this->lru->recordRemoval('nonexistent');

        self::assertSame(0, $this->lru->count());
    }

    public function testNameReturnsLru(): void
    {
        self::assertSame('lru', $this->lru->name());
    }
}
