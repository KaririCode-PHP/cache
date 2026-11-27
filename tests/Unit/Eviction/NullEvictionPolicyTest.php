<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Eviction;

use KaririCode\Cache\Eviction\NullEvictionPolicy;
use PHPUnit\Framework\TestCase;

final class NullEvictionPolicyTest extends TestCase
{
    private NullEvictionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new NullEvictionPolicy();
    }

    public function testEvictAlwaysReturnsEmpty(): void
    {
        $this->policy->recordInsertion('a');

        $result = $this->policy->evict();

        self::assertFalse($result->success);
        self::assertNull($result->evictedKey);
    }

    public function testCountTracksKeys(): void
    {
        $this->policy->recordInsertion('a');
        $this->policy->recordInsertion('b');

        self::assertSame(2, $this->policy->count());
    }

    public function testDuplicateInsertionDoesNotInflateCount(): void
    {
        $this->policy->recordInsertion('a');
        $this->policy->recordInsertion('a');

        self::assertSame(1, $this->policy->count());
    }

    public function testRemovalDecrementsCount(): void
    {
        $this->policy->recordInsertion('a');
        $this->policy->recordRemoval('a');

        self::assertSame(0, $this->policy->count());
    }

    public function testRemoveNonexistentDoesNotGoNegative(): void
    {
        $this->policy->recordRemoval('nonexistent');

        self::assertSame(0, $this->policy->count());
    }

    public function testClearResetsState(): void
    {
        $this->policy->recordInsertion('a');
        $this->policy->clear();

        self::assertSame(0, $this->policy->count());
    }

    public function testNameReturnsNone(): void
    {
        self::assertSame('none', $this->policy->name());
    }
}
