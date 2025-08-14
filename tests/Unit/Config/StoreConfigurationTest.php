<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config;

use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Config\StoreConfiguration;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the StoreConfiguration class.
 *
 * Validates that the Value Object correctly parses and validates configurations
 * for both simple and layered cache stores.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(StoreConfiguration::class)]
final class StoreConfigurationTest extends TestCase
{
    /**
     * Tests that a configuration for a simple, single-layer driver is parsed correctly.
     */
    public function testSimpleDriverConfiguration(): void
    {
        // Arrange
        $configData = [
            'driver' => 'redis',
            'config' => ['host' => 'localhost', 'port' => 6379],
        ];
        $storeConfig = new StoreConfiguration('redis_store', $configData);

        // Assert
        $this->assertSame('redis_store', $storeConfig->getName());
        $this->assertSame('redis', $storeConfig->getDriver());
        $this->assertSame($configData['config'], $storeConfig->getConfig());
        $this->assertSame('localhost', $storeConfig->getOption('host'));
        $this->assertNull($storeConfig->getOption('nonexistent'));
        $this->assertTrue($storeConfig->hasOption('port'));
        $this->assertFalse($storeConfig->isLayered());
        $this->assertNull($storeConfig->getLayeredConfig());
    }

    /**
     * Tests that a configuration for a multi-layer driver is parsed correctly.
     */
    public function testLayeredDriverConfiguration(): void
    {
        // Arrange
        $configData = [
            'driver' => 'layered',
            'config' => [
                'promotion_enabled' => true,
                'layers' => [
                    ['driver' => 'apcu'],
                    ['driver' => 'redis'],
                ],
            ],
        ];
        $storeConfig = new StoreConfiguration('layered_store', $configData);

        // Assert
        $this->assertSame('layered_store', $storeConfig->getName());
        $this->assertSame('layered', $storeConfig->getDriver());
        $this->assertTrue($storeConfig->isLayered());
        $this->assertCount(2, $storeConfig->getLayers());
        $this->assertInstanceOf(LayeredDriverConfig::class, $storeConfig->getLayeredConfig());
        $this->assertTrue($storeConfig->getLayeredConfig()->promotionEnabled);
    }

    /**
     * Tests that the constructor throws a ConfigurationException for various invalid inputs.
     *
     * @param array<string, mixed> $configData the invalid configuration data
     * @param string $expectedMessage the expected exception message
     */
    #[DataProvider('invalidConfigProvider')]
    public function testThrowsExceptionForInvalidConfiguration(array $configData, string $expectedMessage): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        new StoreConfiguration('test', $configData);
    }

    /**
     * Provides sets of invalid configuration data to test validation logic.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'missing driver' => [
                ['config' => ['host' => 'localhost']],
                'Driver must be specified',
            ],
            'empty driver' => [
                ['driver' => ''],
                'Driver must be specified',
            ],
            'layered missing layers' => [
                ['driver' => 'layered', 'config' => []],
                'Layered cache must have a non-empty "layers" array',
            ],
            'layered empty layers' => [
                ['driver' => 'layered', 'config' => ['layers' => []]],
                'Layered cache must have a non-empty "layers" array',
            ],
            'invalid layer config' => [
                ['driver' => 'layered', 'config' => ['layers' => [['priority' => 1]]]],
                'Layer driver must be specified',
            ],
        ];
    }
}
