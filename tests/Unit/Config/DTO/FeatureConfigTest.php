<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\FeatureConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the FeatureConfig DTO.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(FeatureConfig::class)]
final class FeatureConfigTest extends TestCase
{
    /**
     * Tests that the DTO can be created with default values.
     */
    public function testCanBeCreatedWithDefaultValues(): void
    {
        // Act
        $config = FeatureConfig::fromConfig([]);

        // Assert
        $this->assertTrue($config->stampedeProtection);
        $this->assertTrue($config->metricsEnabled);
        $this->assertTrue($config->eventsEnabled);
        $this->assertTrue($config->strictValidation);
        $this->assertTrue($config->compressionEnabled);
        $this->assertFalse($config->asyncOperations);
        $this->assertFalse($config->debugMode);
        $this->assertFalse($config->encryptionEnabled);
        $this->assertNull($config->encryptionKey);
    }

    /**
     * Tests that the DTO properties are correctly set from a configuration array.
     */
    public function testCanBeCreatedWithSpecificValues(): void
    {
        // Arrange
        $data = [
            'stampede_protection' => false,
            'metrics_enabled' => false,
            'events_enabled' => false,
            'strict_validation' => false,
            'compression_enabled' => false,
            'async_operations' => true,
            'debug_mode' => true,
            'encryption_enabled' => true,
            'encryption_key' => 'a-very-secret-key',
        ];

        // Act
        $config = FeatureConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->stampedeProtection);
        $this->assertFalse($config->metricsEnabled);
        $this->assertFalse($config->eventsEnabled);
        $this->assertFalse($config->strictValidation);
        $this->assertFalse($config->compressionEnabled);
        $this->assertTrue($config->asyncOperations);
        $this->assertTrue($config->debugMode);
        $this->assertTrue($config->encryptionEnabled);
        $this->assertSame('a-very-secret-key', $config->encryptionKey);
    }
}
