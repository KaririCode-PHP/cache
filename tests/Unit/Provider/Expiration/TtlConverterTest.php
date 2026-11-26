<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider\Expiration;

use DateInterval;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Provider\Expiration\TtlConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for TtlConverter.
 *
 * Tests demonstrate how Single Responsibility Principle improves testability:
 * - Each test focuses on one conversion type
 * - Clear test boundaries and expectations
 * - Easy to mock and isolate
 *
 * @author Walmir Silva <walmir.silva@kariricode.org>
 *
 * @see "Test Driven Development" by Kent Beck
 * @see "Growing Object-Oriented Software" by Freeman & Pryce
 */
#[CoversClass(TtlConverter::class)]
final class TtlConverterTest extends TestCase
{
    private TtlConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new TtlConverter();
    }

    // ========================================================================
    // Integer TTL Conversion Tests
    // ========================================================================

    public function testToSecondsWithValidInteger(): void
    {
        // Arrange
        $ttl = 3600;

        // Act
        $result = $this->converter->toSeconds($ttl);

        // Assert
        $this->assertSame(3600, $result);
    }

    public function testToSecondsWithZeroInteger(): void
    {
        // Arrange
        $ttl = 0;

        // Act
        $result = $this->converter->toSeconds($ttl);

        // Assert
        $this->assertSame(0, $result);
    }

    public function testToSecondsWithNegativeIntegerThrowsException(): void
    {
        // Arrange
        $ttl = -100;

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL cannot be negative');

        // Act
        $this->converter->toSeconds($ttl);
    }

    // ========================================================================
    // DateInterval TTL Conversion Tests
    // ========================================================================

    public function testToSecondsWithDateInterval(): void
    {
        // Arrange
        $interval = new \DateInterval('PT1H'); // 1 hour

        // Act
        $result = $this->converter->toSeconds($interval);

        // Assert
        $this->assertSame(3600, $result);
    }

    #[DataProvider('dateIntervalProvider')]
    public function testToSecondsWithVariousDateIntervals(string $intervalSpec, int $expectedSeconds): void
    {
        // Arrange
        $interval = new \DateInterval($intervalSpec);

        // Act
        $result = $this->converter->toSeconds($interval);

        // Assert
        $this->assertSame($expectedSeconds, $result);
    }

    public static function dateIntervalProvider(): array
    {
        return [
            'PT30M' => ['PT30M', 1800],   // 30 minutes
            'PT2H' => ['PT2H', 7200],     // 2 hours
            'P1D' => ['P1D', 86400],      // 1 day
        ];
    }

    // ========================================================================
    // DateTime TTL Conversion Tests
    // ========================================================================

    public function testToSecondsWithDateTime(): void
    {
        // Arrange
        $now = new \DateTimeImmutable();
        $future = $now->add(new \DateInterval('PT1H'));

        // Act
        $result = $this->converter->toSeconds($future);

        // Assert
        $this->assertEqualsWithDelta(3600, $result, 1);
    }

    #[Group('NewCoverage')]
    public function testToSecondsWithMutableDateTime(): void
    {
        // Arrange: Use a standard (mutable) DateTime object to cover the else branch.
        $future = new \DateTime('+1 hour');

        // Act
        $result = $this->converter->toSeconds($future);

        // Assert
        $this->assertEqualsWithDelta(3600, $result, 1);
    }

    public function testToSecondsWithPastDateTimeThrowsException(): void
    {
        // Arrange
        $past = new \DateTimeImmutable('-1 hour');

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL cannot be negative');

        // Act
        $this->converter->toSeconds($past);
    }

    // ========================================================================
    // Null TTL Tests
    // ========================================================================

    public function testToSecondsWithNullReturnsNull(): void
    {
        // Act
        $result = $this->converter->toSeconds(null);

        // Assert
        $this->assertNull($result);
    }

    // ========================================================================
    // fromSeconds Tests
    // ========================================================================

    public function testFromSecondsCreatesCorrectDateTime(): void
    {
        // Arrange
        $seconds = 3600;
        $expectedTime = new \DateTimeImmutable('+1 hour');

        // Act
        $result = $this->converter->fromSeconds($seconds);

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    public function testFromSecondsWithZeroThrowsException(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL seconds must be positive');

        // Act
        $this->converter->fromSeconds(0);
    }

    // ========================================================================
    // Validation Tests
    // ========================================================================

    #[DataProvider('validTtlProvider')]
    public function testIsValidReturnsTrueForValidTtl(mixed $ttl): void
    {
        // Act & Assert
        $this->assertTrue($this->converter->isValid($ttl));
    }

    #[DataProvider('invalidTtlProvider')]
    public function testIsValidReturnsFalseForInvalidTtl(mixed $ttl): void
    {
        // Act & Assert
        $this->assertFalse($this->converter->isValid($ttl));
    }

    public static function validTtlProvider(): array
    {
        return [
            'positive integer' => [3600],
            'zero integer' => [0],
            'null' => [null],
            'date interval' => [new \DateInterval('PT1H')],
            'future datetime' => [new \DateTimeImmutable('+1 hour')],
        ];
    }

    public static function invalidTtlProvider(): array
    {
        return [
            'negative integer' => [-100],
            'past datetime' => [new \DateTimeImmutable('-1 hour')],
            'string' => ['invalid'],
            'array' => [[]],
        ];
    }
}
