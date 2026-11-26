<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the MetricsSnapshot Value Object.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(MetricsSnapshot::class)]
final class MetricsSnapshotTest extends TestCase
{
    /**
     * Tests that the object can be created with only the required driver name,
     * and that default values are applied correctly.
     */
    public function testCanBeCreatedWithOnlyDriverNameAndDefaultsAreCorrect(): void
    {
        // Arrange & Act
        $snapshot = new MetricsSnapshot('redis');

        // Assert
        $this->assertSame('redis', $snapshot->driverName);
        $this->assertSame(0, $snapshot->memoryUsage);
        $this->assertSame(0, $snapshot->itemCount);
        $this->assertSame('1.0.0', $snapshot->driverVersion);
        $this->assertSame([], $snapshot->rawStats);
    }

    /**
     * Tests that the object can be created with all properties explicitly provided.
     */
    public function testCanBeCreatedWithAllProperties(): void
    {
        // Arrange
        $rawStats = ['hits' => 100, 'misses' => 20, 'uptime' => 3600];

        // Act
        $snapshot = new MetricsSnapshot(
            driverName: 'memcached',
            memoryUsage: 1024,
            itemCount: 50,
            driverVersion: '1.6.22',
            rawStats: $rawStats
        );

        // Assert
        $this->assertSame('memcached', $snapshot->driverName);
        $this->assertSame(1024, $snapshot->memoryUsage);
        $this->assertSame(50, $snapshot->itemCount);
        $this->assertSame('1.6.22', $snapshot->driverVersion);
        $this->assertSame($rawStats, $snapshot->rawStats);
    }

    /**
     * Tests that creating the object with an empty driver name throws an exception.
     */
    public function testCannotBeCreatedWithEmptyDriverName(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver name cannot be empty.');

        // Act
        new MetricsSnapshot('');
    }

    /**
     * Tests that creating the object with a whitespace-only driver name throws an exception.
     */
    public function testCannotBeCreatedWithWhitespaceOnlyDriverName(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver name cannot be empty.');

        // Act
        new MetricsSnapshot('   ');
    }

    /**
     * Tests that the object accepts negative values for numeric properties,
     * as there is no validation against it.
     */
    public function testCanBeCreatedWithNegativeValues(): void
    {
        // Arrange & Act
        $snapshot = new MetricsSnapshot(
            driverName: 'filesystem',
            memoryUsage: -512,
            itemCount: -10
        );

        // Assert
        $this->assertSame(-512, $snapshot->memoryUsage);
        $this->assertSame(-10, $snapshot->itemCount);
    }

    /**
     * Tests that the rawStats property correctly handles complex nested arrays.
     */
    public function testCanHandleComplexNestedRawStats(): void
    {
        // Arrange
        $complexStats = [
            'server' => ['host' => '127.0.0.1', 'port' => 6379],
            'keyspace' => ['db0' => ['keys' => 100, 'expires' => 50]],
        ];

        // Act
        $snapshot = new MetricsSnapshot(
            driverName: 'redis',
            rawStats: $complexStats
        );

        // Assert
        $this->assertSame($complexStats, $snapshot->rawStats);
        $this->assertSame(100, $snapshot->rawStats['keyspace']['db0']['keys']);
    }
}
