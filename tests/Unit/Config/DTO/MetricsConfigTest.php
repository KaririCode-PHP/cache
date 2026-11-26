<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\MetricsConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the MetricsConfig DTO.
 *
 * Tests the immutable DTO that holds configuration for collecting cache metrics,
 * ensuring proper validation, default values, and type safety according to
 * SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(MetricsConfig::class)]
final class MetricsConfigTest extends TestCase
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
        $config = MetricsConfig::fromConfig([]);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame('default', $config->collector);
        $this->assertSame('json', $config->exportFormat);
        $this->assertSame(60, $config->exportInterval);
        $this->assertTrue($config->includeDriverStats);
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
            'collector' => 'prometheus',
            'export_format' => 'prometheus',
            'export_interval' => 30,
            'include_driver_stats' => false,
        ];

        // Act
        $config = MetricsConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->enabled);
        $this->assertSame('prometheus', $config->collector);
        $this->assertSame('prometheus', $config->exportFormat);
        $this->assertSame(30, $config->exportInterval);
        $this->assertFalse($config->includeDriverStats);
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
            'collector' => 'custom',
            // Missing export_format, export_interval, include_driver_stats
        ];

        // Act
        $config = MetricsConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->enabled);
        $this->assertSame('custom', $config->collector);
        $this->assertSame('json', $config->exportFormat); // Default value
        $this->assertSame(60, $config->exportInterval); // Default value
        $this->assertTrue($config->includeDriverStats); // Default value
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
            'enabled' => 'true', // String that should be cast to boolean
            'collector' => 'statsd', // Already string, no casting needed
            'export_format' => 'influxdb', // Already string, no casting needed
            'export_interval' => '120', // String that should be cast to integer
            'include_driver_stats' => '0', // String that should be cast to boolean
        ];

        // Act
        $config = MetricsConfig::fromConfig($data);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertSame('statsd', $config->collector);
        $this->assertSame('influxdb', $config->exportFormat);
        $this->assertSame(120, $config->exportInterval);
        $this->assertFalse($config->includeDriverStats);
    }

    /**
     * Tests various export format configurations.
     *
     * This test ensures that different metric export formats are properly
     * handled according to industry standards.
     */
    public function testSupportsVariousExportFormats(): void
    {
        // Arrange & Act & Assert
        $jsonConfig = MetricsConfig::fromConfig(['export_format' => 'json']);
        $this->assertSame('json', $jsonConfig->exportFormat);

        $prometheusConfig = MetricsConfig::fromConfig(['export_format' => 'prometheus']);
        $this->assertSame('prometheus', $prometheusConfig->exportFormat);

        $influxConfig = MetricsConfig::fromConfig(['export_format' => 'influxdb']);
        $this->assertSame('influxdb', $influxConfig->exportFormat);

        $csvConfig = MetricsConfig::fromConfig(['export_format' => 'csv']);
        $this->assertSame('csv', $csvConfig->exportFormat);
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
        $config = MetricsConfig::fromConfig(['enabled' => true]);

        // Assert - Verify all properties are readonly
        $reflection = new \ReflectionClass($config);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }
}
