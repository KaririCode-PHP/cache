<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Eviction\LfuPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Frequencies count every access, and the minimum frequency follows a key
 * that leaves the lowest bucket.
 */
final class LfuFrequencyTest extends TestCase
{
    public function testTheLeastFrequentlyUsedKeyIsEvictedFirst(): void
    {
        $policy = new LfuPolicy(capacity: 10);
        $policy->recordInsertion('a');
        $policy->recordInsertion('b');
        $policy->recordAccess('a');
        $policy->recordAccess('a');
        $policy->recordAccess('b');

        self::assertSame('b', $policy->evict()->evictedKey);
        self::assertSame('a', $policy->evict()->evictedKey);
        self::assertFalse($policy->evict()->success);
    }
}
