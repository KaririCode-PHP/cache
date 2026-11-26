<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider\Expiration;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Provider\Expiration\TtlConstraintApplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for TtlConstraintApplier.
 *
 * Tests constraint application logic in isolation.
 */
#[CoversClass(TtlConstraintApplier::class)]
final class TtlConstraintApplierTest extends TestCase
{
    private TtlConstraintApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new TtlConstraintApplier();
    }

    public function testApplyMaxTtlWithinLimit(): void
    {
        // Arrange
        $ttl = 1800;
        $maxTtl = 3600;

        // Act
        $result = $this->applier->applyMaxTtl($ttl, $maxTtl);

        // Assert
        $this->assertSame(1800, $result);
    }

    public function testApplyMaxTtlExceedsLimit(): void
    {
        // Arrange
        $ttl = 7200;
        $maxTtl = 3600;

        // Act
        $result = $this->applier->applyMaxTtl($ttl, $maxTtl);

        // Assert
        $this->assertSame(3600, $result);
    }

    public function testValidateConstraintsWithValidTtl(): void
    {
        // Arrange
        $ttl = 3600;

        // Act & Assert
        $this->applier->validateConstraints($ttl);
        $this->addToAssertionCount(1); // No exception thrown
    }

    public function testValidateConstraintsWithTooLowTtl(): void
    {
        // Arrange
        $ttl = 0;

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be at least 1 seconds');

        // Act
        $this->applier->validateConstraints($ttl);
    }

    public function testValidateConstraintsWithTooHighTtl(): void
    {
        // Arrange
        $ttl = 3000000; // Exceeds default max
        $maxTtl = 86400; // 1 day

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL cannot exceed 86400 seconds');

        // Act
        $this->applier->validateConstraints($ttl, $maxTtl);
    }

    /**
     * This test covers the `applyAllConstraints` method.
     * It ensures the method correctly caps the TTL value without throwing an exception.
     */
    public function testApplyAllConstraintsAppliesCapCorrectly(): void
    {
        // Arrange
        $ttl = 7200; // 2 hours
        $maxTtl = 3600; // 1 hour

        // Act
        $result = $this->applier->applyAllConstraints($ttl, $maxTtl);

        // Assert
        $this->assertSame(3600, $result, 'The TTL should be capped at the maximum value.');
    }
}
