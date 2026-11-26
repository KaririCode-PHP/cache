<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider\Expiration;

use KaririCode\Cache\Provider\Expiration\SlidingExpirationHandler;
use KaririCode\Cache\Provider\Expiration\TtlConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for SlidingExpirationHandler.
 *
 * Isolated testing of sliding expiration logic without
 * interference from other concerns.
 */
#[CoversClass(SlidingExpirationHandler::class)]
final class SlidingExpirationHandlerTest extends TestCase
{
    private SlidingExpirationHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new SlidingExpirationHandler(new TtlConverter());
    }

    public function testShouldUpdateWithSlidingEnabledAndValidExpiration(): void
    {
        // Arrange
        $expiration = new \DateTimeImmutable('+1 hour');

        // Act
        $result = $this->handler->shouldUpdate($expiration, true);

        // Assert
        $this->assertTrue($result);
    }

    public function testShouldUpdateWithSlidingDisabled(): void
    {
        // Arrange
        $expiration = new \DateTimeImmutable('+1 hour');

        // Act
        $result = $this->handler->shouldUpdate($expiration, false);

        // Assert
        $this->assertFalse($result);
    }

    public function testShouldUpdateWithExpiredTime(): void
    {
        // Arrange
        $expiration = new \DateTimeImmutable('-1 hour');

        // Act
        $result = $this->handler->shouldUpdate($expiration, true);

        // Assert
        $this->assertFalse($result);
    }

    public function testUpdateExpirationExtendsTime(): void
    {
        // Arrange
        $currentExpiration = new \DateTimeImmutable('+30 minutes');
        $slidingTtl = 3600; // 1 hour
        $expectedTime = new \DateTimeImmutable('+1 hour');

        // Act
        $result = $this->handler->updateExpiration($currentExpiration, $slidingTtl, 'test:key', []);

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    /**
     * Ensures that if an item is already expired, its expiration time is not updated.
     * This covers the remaining branch in the `updateExpiration` method.
     */
    public function testUpdateExpirationDoesNotChangeExpiredTime(): void
    {
        // Arrange
        $currentExpiration = new \DateTimeImmutable('-30 minutes'); // Already expired
        $slidingTtl = 3600;

        // Act
        $result = $this->handler->updateExpiration($currentExpiration, $slidingTtl, 'test:key', []);

        // Assert: The returned object should be the same as the one passed in.
        $this->assertSame($currentExpiration, $result);
    }
}
