<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Eviction\SievePolicy;
use PHPUnit\Framework\TestCase;

final class SievePolicyTest extends TestCase
{
    private SievePolicy $sieve;

    protected function setUp(): void
    {
        $this->sieve = new SievePolicy();
    }

    public function testEvictFromEmptyCacheReturnsEmpty(): void
    {
        $result = $this->sieve->evict();

        self::assertFalse($result->success);
        self::assertNull($result->evictedKey);
    }

    public function testInsertionAndCountTracking(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordInsertion('c');

        self::assertSame(3, $this->sieve->count());
    }

    public function testEvictsUnvisitedEntry(): void
    {
        // Insert a, b, c — none visited
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordInsertion('c');

        // Hand starts at tail (a), which is unvisited → evict a
        $result = $this->sieve->evict();

        self::assertTrue($result->success);
        self::assertSame('a', $result->evictedKey);
        self::assertSame(2, $this->sieve->count());
    }

    public function testVisitedEntrySkippedDuringEviction(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordInsertion('c');

        // Visit a → visited=1
        $this->sieve->recordAccess('a');

        // Evict: hand at tail (a), visited=1 → reset, advance to b
        // b is unvisited → evict b
        $result = $this->sieve->evict();

        self::assertTrue($result->success);
        self::assertSame('b', $result->evictedKey);
    }

    public function testLazyPromotionDoesNotReorderList(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordInsertion('c');

        // Access a, b (set visited bits)
        $this->sieve->recordAccess('a');
        $this->sieve->recordAccess('b');

        // Evict: hand starts at tail (a), visited → reset → advance to b
        // b visited → reset → advance to c
        // c not visited → evict c
        $result = $this->sieve->evict();

        self::assertSame('c', $result->evictedKey);
    }

    public function testQuickDemotionOfNewEntries(): void
    {
        // Insert popular items
        $this->sieve->recordInsertion('popular1');
        $this->sieve->recordInsertion('popular2');
        $this->sieve->recordAccess('popular1');
        $this->sieve->recordAccess('popular2');

        // Insert new item (not accessed)
        $this->sieve->recordInsertion('new_item');

        // After evicting popular items' visited bits are reset,
        // the new_item or the reset items get evicted
        $result = $this->sieve->evict();

        self::assertTrue($result->success);
    }

    public function testRemovalUpdatesCount(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordRemoval('a');

        self::assertSame(1, $this->sieve->count());
    }

    public function testClearResetsAllState(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordAccess('a');
        $this->sieve->clear();

        self::assertSame(0, $this->sieve->count());

        $result = $this->sieve->evict();
        self::assertFalse($result->success);
    }

    public function testNameReturnsSieve(): void
    {
        self::assertSame('sieve', $this->sieve->name());
    }

    public function testIdempotentReinsertionDoesNotDuplicate(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('a');

        self::assertSame(1, $this->sieve->count());
    }

    public function testFullEvictionCycle(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordInsertion('c');

        $evicted = [];

        for ($i = 0; $i < 3; ++$i) {
            $result = $this->sieve->evict();
            if ($result->success) {
                $evicted[] = $result->evictedKey;
            }
        }

        self::assertCount(3, $evicted);
        self::assertSame(0, $this->sieve->count());
    }

    public function testAllVisitedWithHandAtTailEvictsTheTail(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordAccess('a');
        $this->sieve->recordAccess('b');

        // The hand starts at the tail: one rotation clears both bits and the
        // scan comes back to 'a', now unvisited. The mid-list case is covered
        // by EvictionPolicyInvariantTest.
        self::assertSame('a', $this->sieve->evict()->evictedKey);
        self::assertSame(1, $this->sieve->count());
    }

    public function testRemoveNodeThatHandPointsTo(): void
    {
        $this->sieve->recordInsertion('a');
        $this->sieve->recordInsertion('b');
        $this->sieve->recordInsertion('c');

        // Trigger a partial eviction to move hand
        $this->sieve->recordAccess('a');
        $this->sieve->evict(); // hand moves, evicts b (or c)

        // Remove remaining entries
        $this->sieve->recordRemoval('a');
        $this->sieve->recordRemoval('c');

        self::assertTrue($this->sieve->count() <= 1);
    }
}
