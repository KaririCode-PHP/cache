<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\HealthCheckConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the HealthCheckConfig DTO.
 *
 * Tests the immutable DTO that holds configuration for cache health checks,
 * ensuring proper validation, default values, and immutability patterns
 * according to SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(HealthCheckConfig::class)]
final class HealthCheckConfigTest extends TestCase
{
    /**
     * Tests that the DTO can be created with default values when no config is provided.
     *
     * This test ensures that the factory method provides sensible defaults
     * following the Fail-Safe Defaults principle.
     */
    public function testCanBeCreatedWithDefaultValues(): void
    {
        // Act
        $config = HealthCheckConfig::fromConfig([]);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame(300, $config->interval); // 5 minutes
        $this->assertSame(5, $config->timeout);
        $this->assertSame(['redis', 'memcached'], $config->storesToCheck);
        $this->assertSame(3, $config->failureThreshold);
        $this->assertSame(2, $config->recoveryThreshold);
    }

    /**
     * Tests that the DTO properties are correctly set from a configuration array.
     *
     * This test verifies the Value Object pattern implementation and ensures
     * that all properties are correctly mapped from configuration.
     */
    public function testCanBeCreatedWithSpecificValues(): void
    {
        // Arrange
        $data = [
            'enabled' => false,
            'interval' => 600,
            'timeout' => 10,
            'stores_to_check' => ['redis', 'memcached', 'filesystem'],
            'failure_threshold' => 5,
            'recovery_threshold' => 3,
        ];

        // Act
        $config = HealthCheckConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->enabled);
        $this->assertSame(600, $config->interval);
        $this->assertSame(10, $config->timeout);
        $this->assertSame(['redis', 'memcached', 'filesystem'], $config->storesToCheck);
        $this->assertSame(5, $config->failureThreshold);
        $this->assertSame(3, $config->recoveryThreshold);
    }

    /**
     * Tests that the DTO correctly handles partial configuration arrays.
     *
     * This test ensures that the factory method gracefully handles
     * incomplete configuration by using defaults for missing values.
     */
    public function testCanBeCreatedWithPartialConfiguration(): void
    {
        // Arrange
        $data = [
            'enabled' => false,
            'interval' => 120,
            // Missing timeout, stores_to_check, failure_threshold, recovery_threshold
        ];

        // Act
        $config = HealthCheckConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->enabled);
        $this->assertSame(120, $config->interval);
        $this->assertSame(5, $config->timeout); // Default value
        $this->assertSame(['redis', 'memcached'], $config->storesToCheck); // Default value
        $this->assertSame(3, $config->failureThreshold); // Default value
        $this->assertSame(2, $config->recoveryThreshold); // Default value
    }

    /**
     * Tests that the DTO properly handles type casting from configuration.
     *
     * This test ensures robustness when dealing with configuration values
     * that may come from different sources (e.g., environment variables as strings).
     */
    public function testCorrectlyHandlesTypeCasting(): void
    {
        // Arrange - Simulating values that might come from environment variables
        $data = [
            'enabled' => '1', // String that should be cast to boolean
            'interval' => '450', // String that should be cast to integer
            'timeout' => '15', // String that should be cast to integer
            'failure_threshold' => '7', // String that should be cast to integer
            'recovery_threshold' => '4', // String that should be cast to integer
        ];

        // Act
        $config = HealthCheckConfig::fromConfig($data);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame(450, $config->interval);
        $this->assertSame(15, $config->timeout);
        $this->assertSame(7, $config->failureThreshold);
        $this->assertSame(4, $config->recoveryThreshold);
    }

    /**
     * Tests that the DTO is truly immutable.
     *
     * This test verifies that all properties are readonly and the object
     * cannot be modified after instantiation, following Value Object principles.
     */
    public function testIsImmutable(): void
    {
        // Arrange
        $config = HealthCheckConfig::fromConfig(['enabled' => true]);

        // Assert - Verify all properties are readonly
        $reflection = new \ReflectionClass($config);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }
}
