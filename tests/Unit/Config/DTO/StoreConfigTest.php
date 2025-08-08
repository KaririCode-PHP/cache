<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\LayerConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the StoreConfig DTO.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(StoreConfig::class)]
final class StoreConfigTest extends TestCase
{
    /**
     * Tests successful creation with a simple driver.
     */
    public function testCanBeCreatedWithSimpleDriver(): void
    {
        // Arrange
        $data = [
            'driver' => 'redis',
            'config' => ['host' => 'localhost', 'port' => 6379],
        ];

        // Act
        $config = StoreConfig::fromConfig($data);

        // Assert
        $this->assertSame('redis', $config->driver);
        $this->assertSame(['host' => 'localhost', 'port' => 6379], $config->config);
        $this->assertEmpty($config->layers);
    }

    /**
     * Tests successful creation with a layered driver.
     */
    public function testCanBeCreatedWithLayeredDriver(): void
    {
        // Arrange
        $data = [
            'driver' => 'layered',
            'config' => [
                'layers' => [
                    ['driver' => 'apcu', 'priority' => 1],
                    ['driver' => 'redis', 'priority' => 10, 'config' => ['db' => 1]],
                ],
            ],
        ];

        // Act
        $config = StoreConfig::fromConfig($data);

        // Assert
        $this->assertSame('layered', $config->driver);
        $this->assertCount(2, $config->layers);
        $this->assertInstanceOf(LayerConfig::class, $config->layers[0]);
        $this->assertSame('apcu', $config->layers[0]->driver);
        $this->assertSame(1, $config->layers[0]->priority);
        $this->assertSame('redis', $config->layers[1]->driver);
        $this->assertSame(['db' => 1], $config->layers[1]->config);
    }

    /**
     * Tests that an exception is thrown for invalid configuration.
     *
     * @param array<string, mixed> $invalidData
     */
    #[DataProvider('invalidConfigProvider')]
    public function testThrowsExceptionForInvalidConfiguration(array $invalidData): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);

        // Act
        StoreConfig::fromConfig($invalidData);
    }

    /**
     * Provides invalid configuration data for testing.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'missing driver' => [['config' => []]],
            'empty driver' => [['driver' => '']],
            'invalid layer (not an array)' => [[
                'driver' => 'layered',
                'config' => ['layers' => ['not_an_array']],
            ]],
            'invalid layer (missing driver)' => [[
                'driver' => 'layered',
                'config' => ['layers' => [['priority' => 1]]],
            ]],
        ];
    }
}
