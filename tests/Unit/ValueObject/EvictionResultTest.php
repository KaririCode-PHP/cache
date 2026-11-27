<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\ValueObject;

use KaririCode\Cache\ValueObject\EvictionResult;
use PHPUnit\Framework\TestCase;

final class EvictionResultTest extends TestCase
{
    public function testEmptyFactoryReturnsFailedResult(): void
    {
        $result = EvictionResult::empty();

        self::assertFalse($result->success);
        self::assertNull($result->evictedKey);
    }

    public function testEvictedFactoryReturnsSuccessWithKey(): void
    {
        $result = EvictionResult::evicted('user.42');

        self::assertTrue($result->success);
        self::assertSame('user.42', $result->evictedKey);
    }

    public function testDirectConstruction(): void
    {
        $result = new EvictionResult(evictedKey: 'key', success: true);

        self::assertTrue($result->success);
        self::assertSame('key', $result->evictedKey);
    }
}
