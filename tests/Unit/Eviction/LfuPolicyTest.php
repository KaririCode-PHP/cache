<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Eviction\LfuPolicy;
use PHPUnit\Framework\TestCase;

final class LfuPolicyTest extends TestCase
{
    private LfuPolicy $lfu;

    protected function setUp(): void
    {
        $this->lfu = new LfuPolicy();
    }

    public function testEvictFromEmptyReturnsEmpty(): void
    {
        self::assertFalse($this->lfu->evict()->success);
    }

    public function testEvictsLeastFrequentlyUsed(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');
        $this->lfu->recordInsertion('c');

        // 'b' and 'c' get accessed, 'a' stays at freq=1
        $this->lfu->recordAccess('b');
        $this->lfu->recordAccess('c');

        $result = $this->lfu->evict();

        self::assertSame('a', $result->evictedKey);
    }

    public function testTiesBrokenByOldest(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');
        $this->lfu->recordInsertion('c');

        // All at freq=1 — 'a' is oldest
        $result = $this->lfu->evict();

        self::assertSame('a', $result->evictedKey);
    }

    public function testAccessIncreasesFrequency(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');

        // Access 'a' twice → freq=3, 'b' stays at freq=1
        $this->lfu->recordAccess('a');
        $this->lfu->recordAccess('a');

        $result = $this->lfu->evict();

        self::assertSame('b', $result->evictedKey);
    }

    public function testReinsertionBumpsFrequency(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');

        // Re-insert 'a' → treated as access (freq=2)
        $this->lfu->recordInsertion('a');

        $result = $this->lfu->evict();

        self::assertSame('b', $result->evictedKey);
    }

    public function testRemovalUpdatesCount(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');
        $this->lfu->recordRemoval('a');

        self::assertSame(1, $this->lfu->count());
    }

    public function testRemoveNonexistentKeyIsNoop(): void
    {
        $this->lfu->recordRemoval('nonexistent');

        self::assertSame(0, $this->lfu->count());
    }

    public function testClearResetsState(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordAccess('a');
        $this->lfu->clear();

        self::assertSame(0, $this->lfu->count());
    }

    public function testFullEvictionCycle(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');
        $this->lfu->recordInsertion('c');

        $evicted = [];

        for ($i = 0; $i < 3; ++$i) {
            $result = $this->lfu->evict();

            if ($result->success) {
                $evicted[] = $result->evictedKey;
            }
        }

        self::assertCount(3, $evicted);
        self::assertSame(0, $this->lfu->count());
    }

    public function testAccessNonexistentKeyIsNoop(): void
    {
        $this->lfu->recordAccess('nonexistent');

        self::assertSame(0, $this->lfu->count());
    }

    public function testMinFrequencyUpdatesAfterEviction(): void
    {
        $this->lfu->recordInsertion('a');
        $this->lfu->recordInsertion('b');

        $this->lfu->recordAccess('a');
        // a=freq2, b=freq1

        $this->lfu->evict(); // evicts b (freq=1)

        // Next eviction should get 'a' (freq=2)
        $result = $this->lfu->evict();

        self::assertSame('a', $result->evictedKey);
    }

    public function testNameReturnsLfu(): void
    {
        self::assertSame('lfu', $this->lfu->name());
    }
}
