<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Eviction\FifoPolicy;
use KaririCode\Cache\Eviction\LfuPolicy;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Eviction\SievePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Model-based checks: random operation sequences against a reference set.
 */
final class EvictionPolicyInvariantTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): EvictionPolicy}>
     */
    public static function policies(): iterable
    {
        yield 'sieve' => [static fn(): EvictionPolicy => new SievePolicy()];
        yield 'lru' => [static fn(): EvictionPolicy => new LruPolicy()];
        yield 'lfu' => [static fn(): EvictionPolicy => new LfuPolicy()];
        yield 'fifo' => [static fn(): EvictionPolicy => new FifoPolicy()];
    }

    /**
     * @param \Closure(): EvictionPolicy $make
     */
    #[DataProvider('policies')]
    public function testRandomOperationsKeepTheTrackedSetConsistent(\Closure $make): void
    {
        mt_srand(20260930);
        $policy = $make();
        /** @var array<string, true> $model */
        $model = [];

        for ($step = 0; $step < 5000; ++$step) {
            $key = (string) mt_rand(0, 60);    // numeric-string keys on purpose
            $op = mt_rand(0, 9);

            if ($op < 4) {
                $policy->recordInsertion($key);
                $model[$key] = true;
            } elseif ($op < 7) {
                $policy->recordAccess($key);
            } elseif ($op < 9) {
                $policy->recordRemoval($key);
                unset($model[$key]);
            } else {
                $result = $policy->evict();

                if ([] === $model) {
                    self::assertFalse($result->success);
                } else {
                    self::assertTrue($result->success, "step {$step}: nothing evicted while " . count($model) . ' keys are tracked');
                    self::assertIsString($result->evictedKey);
                    self::assertArrayHasKey($result->evictedKey, $model, "step {$step}: evicted an untracked key");
                    unset($model[$result->evictedKey]);
                }
            }

            self::assertSame(count($model), $policy->count(), "step {$step}");
        }

        $drained = [];
        while (($result = $policy->evict())->success) {
            $drained[] = $result->evictedKey;
        }

        sort($drained);
        $expected = array_map(strval(...), array_keys($model));
        sort($expected);
        self::assertSame($expected, $drained, 'every tracked key is evicted exactly once');
    }

    public function testLfuStillEvictsAfterTheLeastUsedKeyIsRemoved(): void
    {
        $lfu = new LfuPolicy();
        $lfu->recordInsertion('a');
        $lfu->recordInsertion('b');

        for ($i = 0; $i < 50; ++$i) {
            $lfu->recordAccess('b');
        }

        $lfu->recordRemoval('a');

        self::assertSame('b', $lfu->evict()->evictedKey);
    }

    public function testFifoTreatsAReinsertedKeyAsTheNewest(): void
    {
        $fifo = new FifoPolicy();
        foreach (['a', 'b', 'c'] as $key) {
            $fifo->recordInsertion($key);
        }

        $fifo->recordRemoval('a');
        $fifo->recordInsertion('a');

        self::assertSame('b', $fifo->evict()->evictedKey);
    }

    public function testSieveEvictsTheEntryAtTheHandWhenEverythingWasVisited(): void
    {
        $sieve = new SievePolicy();
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $sieve->recordInsertion($key);
        }

        $sieve->recordAccess('a');
        self::assertSame('b', $sieve->evict()->evictedKey);     // hand now at c

        foreach (['a', 'c', 'd'] as $key) {
            $sieve->recordAccess($key);
        }

        // Paper: one rotation clears every bit, then the hand's entry (c) goes.
        self::assertSame('c', $sieve->evict()->evictedKey);
    }
}
