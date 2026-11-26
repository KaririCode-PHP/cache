<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\LoggingConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LoggingConfig DTO.
 *
 * Tests the immutable DTO that holds configuration for logging cache operations,
 * ensuring proper validation, default values, and security considerations
 * according to SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(LoggingConfig::class)]
final class LoggingConfigTest extends TestCase
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
        $config = LoggingConfig::fromConfig([]);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame('cache', $config->channel);
        $this->assertSame('info', $config->level);
        $this->assertFalse($config->includeValues);
        $this->assertSame(100, $config->maxValueLength);
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
            'channel' => 'custom_cache_log',
            'level' => 'debug',
            'include_values' => true,
            'max_value_length' => 200,
        ];

        // Act
        $config = LoggingConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->enabled);
        $this->assertSame('custom_cache_log', $config->channel);
        $this->assertSame('debug', $config->level);
        $this->assertTrue($config->includeValues);
        $this->assertSame(200, $config->maxValueLength);
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
            'enabled' => true,
            'level' => 'warning',
            // Missing channel, include_values, max_value_length
        ];

        // Act
        $config = LoggingConfig::fromConfig($data);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame('cache', $config->channel); // Default value
        $this->assertSame('warning', $config->level);
        $this->assertFalse($config->includeValues); // Default value (security conscious)
        $this->assertSame(100, $config->maxValueLength); // Default value
    }

    /**
     * Tests that the DTO properly handles type casting from configuration.
     *
     * This test ensures robustness when dealing with configuration values
     * that may come from different sources with different types.
     */
    public function testCorrectlyHandlesTypeCasting(): void
    {
        // Arrange - Simulating values that might come from environment variables
        $data = [
            'enabled' => '1', // String that should be cast to boolean
            'channel' => 'app_cache', // Already string, no casting needed
            'level' => 'error', // Already string, no casting needed
            'include_values' => 'true', // String that should be cast to boolean
            'max_value_length' => '50', // String that should be cast to integer
        ];

        // Act
        $config = LoggingConfig::fromConfig($data);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame('app_cache', $config->channel);
        $this->assertSame('error', $config->level);
        $this->assertTrue($config->includeValues);
        $this->assertSame(50, $config->maxValueLength);
    }

    /**
     * Tests various log level configurations.
     *
     * This test ensures that different PSR-3 compatible log levels are properly
     * handled according to industry standards.
     */
    public function testSupportsStandardLogLevels(): void
    {
        // Arrange & Act & Assert
        $debugConfig = LoggingConfig::fromConfig(['level' => 'debug']);
        $this->assertSame('debug', $debugConfig->level);

        $infoConfig = LoggingConfig::fromConfig(['level' => 'info']);
        $this->assertSame('info', $infoConfig->level);

        $warningConfig = LoggingConfig::fromConfig(['level' => 'warning']);
        $this->assertSame('warning', $warningConfig->level);

        $errorConfig = LoggingConfig::fromConfig(['level' => 'error']);
        $this->assertSame('error', $errorConfig->level);

        $criticalConfig = LoggingConfig::fromConfig(['level' => 'critical']);
        $this->assertSame('critical', $criticalConfig->level);
    }

    /**
     * Tests security considerations for value inclusion in logs.
     *
     * This test ensures that the default behavior is security-conscious
     * by not including potentially sensitive cached values in logs.
     */
    public function testSecurityConsciousDefaults(): void
    {
        // Act
        $defaultConfig = LoggingConfig::fromConfig([]);

        // Assert - By default, values should not be included for security
        $this->assertFalse($defaultConfig->includeValues);
        $this->assertSame(100, $defaultConfig->maxValueLength); // Reasonable limit
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
        $config = LoggingConfig::fromConfig(['enabled' => true]);

        // Assert - Verify all properties are readonly
        $reflection = new \ReflectionClass($config);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }
}
