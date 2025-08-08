<?php

declare(strict_types=1);

namespace KaririCode\Cache\Test\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\LayerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LayerConfig DTO.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(LayerConfig::class)]
final class LayerConfigTest extends TestCase
{
    /**
     * Tests that the DTO can be instantiated correctly and properties are set.
     */
    public function testCanBeCreatedAndPropertiesAreSet(): void
    {
        // Arrange
        $driver = 'redis';
        $configData = ['host' => 'localhost'];
        $priority = 10;

        // Act
        $layerConfig = new LayerConfig($driver, $configData, $priority);

        // Assert
        $this->assertSame($driver, $layerConfig->driver);
        $this->assertSame($configData, $layerConfig->config);
        $this->assertSame($priority, $layerConfig->priority);
    }
}
