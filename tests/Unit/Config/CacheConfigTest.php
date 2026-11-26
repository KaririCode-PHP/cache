<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\CacheConfiguration;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CacheConfig class.
 *
 * Tests the enhanced cache configuration manager integrated with KaririCode\Configurator,
 * ensuring proper delegation, namespace handling, and configuration processing
 * according to SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(CacheConfig::class)]
final class CacheConfigTest extends TestCase
{
    private ConfigurationManager&MockObject $configuratorMock;
    private array $sampleConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuratorMock = $this->createMock(ConfigurationManager::class);
        $this->sampleConfiguration = [
            'default' => 'redis',
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'config' => ['host' => 'localhost'],
                ],
                'array' => [
                    'driver' => 'array',
                ],
            ],
            'serializer' => 'json',
            'serializers' => [
                'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
            ],
        ];
    }

    /**
     * Tests that CacheConfig can be created with default namespace.
     */
    public function testCanBeCreatedWithDefaultNamespace(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with('cache')
            ->willReturn($this->sampleConfiguration);

        // Act
        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Assert
        $this->assertInstanceOf(CacheConfig::class, $cacheConfig);
    }

    /**
     * Tests that getStore throws an exception for a non-existent store.
     * This covers the remaining branch in the getStore method.
     */
    public function testGetStoreThrowsExceptionForNonExistentStore(): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Cache store 'nonexistent' is not configured");

        // Arrange
        $this->configuratorMock
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        // The constructor calls get('cache'), while getStore() eventually calls get('cache.stores').
        // The mock must be able to respond correctly to both scenarios.
        $this->configuratorMock
            ->method('get')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'cache' => $this->sampleConfiguration,
                    'cache.stores' => $this->sampleConfiguration['stores'],
                    default => $default,
                };
            });

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act
        $cacheConfig->getStore('nonexistent');
    }

    /**
     * Tests that CacheConfig can be created with custom namespace.
     */
    public function testCanBeCreatedWithCustomNamespace(): void
    {
        // Arrange
        $customNamespace = 'custom_cache';

        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with($customNamespace)
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with($customNamespace)
            ->willReturn($this->sampleConfiguration);

        // Act
        $cacheConfig = new CacheConfig($this->configuratorMock, $customNamespace);

        // Assert
        $this->assertInstanceOf(CacheConfig::class, $cacheConfig);
    }

    /**
     * Tests the static factory method fromConfigurator.
     */
    public function testCanBeCreatedFromConfiguratorFactory(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with('cache')
            ->willReturn($this->sampleConfiguration);

        // Act
        $cacheConfig = CacheConfig::fromConfigurator($this->configuratorMock);

        // Assert
        $this->assertInstanceOf(CacheConfig::class, $cacheConfig);
    }

    /**
     * Tests the get method with namespace support.
     */
    public function testGetMethodWithNamespaceSupport(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                if ('cache' === $key) {
                    return $this->sampleConfiguration;
                }
                if ('cache.default' === $key) {
                    return 'redis';
                }

                return $default;
            });

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act
        $result = $cacheConfig->get('default');

        // Assert
        $this->assertSame('redis', $result);
    }

    /**
     * Tests the get method with default value.
     */
    public function testGetMethodWithDefaultValue(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                if ('cache' === $key) {
                    return $this->sampleConfiguration;
                }
                if ('cache.nonexistent' === $key) {
                    return $default; // Return the provided default
                }

                return $default;
            });

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act
        $result = $cacheConfig->get('nonexistent', 'default_value');

        // Assert
        $this->assertSame('default_value', $result);
    }

    /**
     * Tests the has method with namespace support.
     */
    public function testHasMethodWithNamespaceSupport(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->atLeastOnce())
            ->method('has')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cache' => true,
                    'cache.default' => true,
                    'cache.nonexistent' => false,
                    default => false,
                };
            });

        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with('cache')
            ->willReturn($this->sampleConfiguration);

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act & Assert
        $this->assertTrue($cacheConfig->has('default'));
        $this->assertFalse($cacheConfig->has('nonexistent'));
    }

    /**
     * Tests the hasStore method.
     */
    public function testHasStoreMethod(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'cache' => $this->sampleConfiguration,
                    'cache.stores' => $this->sampleConfiguration['stores'],
                    default => $default,
                };
            });

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act & Assert
        $this->assertTrue($cacheConfig->hasStore('redis'));
        $this->assertTrue($cacheConfig->hasStore('array'));
        $this->assertFalse($cacheConfig->hasStore('nonexistent'));
    }

    /**
     * Tests delegation to underlying configuration provider methods.
     */
    public function testDelegatesToUnderlyingConfiguration(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'cache' => $this->sampleConfiguration,
                    'cache.default' => 'redis',
                    'cache.stores' => $this->sampleConfiguration['stores'],
                    'cache.serializer' => 'json',
                    'cache.serializers' => $this->sampleConfiguration['serializers'],
                    'cache.features' => [],
                    'cache.validation' => [],
                    'cache.metrics' => [],
                    'cache.logging' => [],
                    'cache.health_checks' => [],
                    'cache.expiration_provider' => [],
                    'cache.optimizations' => [],
                    default => $default,
                };
            });

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act & Assert - Test delegation of various methods
        $this->assertSame('redis', $cacheConfig->getDefaultStore());
        $this->assertIsArray($cacheConfig->getStores());
        $this->assertCount(2, $cacheConfig->getStores());
        $this->assertInstanceOf(StoreConfig::class, $cacheConfig->getStore('redis'));
        $this->assertSame('json', $cacheConfig->getDefaultSerializer());
        $this->assertIsArray($cacheConfig->getSerializers());

        // Test DTO methods
        $this->assertNotNull($cacheConfig->getFeatures());
        $this->assertNotNull($cacheConfig->getValidation());
        $this->assertNotNull($cacheConfig->getMetrics());
        $this->assertNotNull($cacheConfig->getLogging());
        $this->assertNotNull($cacheConfig->getHealthChecks());
        $this->assertNotNull($cacheConfig->getExpirationProvider());
        $this->assertNotNull($cacheConfig->getOptimizations());
    }

    /**
     * Tests conversion to CacheConfiguration.
     */
    public function testToCacheConfiguration(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'cache' => $this->sampleConfiguration,
                    'cache.default' => 'redis',
                    'cache.stores' => $this->sampleConfiguration['stores'],
                    'cache.serializer' => 'json',
                    'cache.serializers' => $this->sampleConfiguration['serializers'],
                    'cache.features' => [],
                    'cache.validation' => [],
                    'cache.metrics' => [],
                    'cache.logging' => [],
                    'cache.health_checks' => [],
                    'cache.expiration_provider' => [],
                    'cache.optimizations' => [],
                    default => $default,
                };
            });

        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Act
        $cacheConfiguration = $cacheConfig->toCacheConfiguration();

        // Assert
        $this->assertInstanceOf(CacheConfiguration::class, $cacheConfiguration);
        $this->assertSame('redis', $cacheConfiguration->getDefaultStore());
        $this->assertSame('json', $cacheConfiguration->getDefaultSerializer());
    }

    /**
     * Tests handling of missing configuration namespace.
     */
    public function testHandlesMissingConfigurationNamespace(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(false);

        $this->configuratorMock
            ->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                // When namespace doesn't exist, return defaults
                return match ($key) {
                    'cache.default' => $default ?? 'array',
                    'cache.serializer' => $default ?? 'native',
                    'cache.stores' => $default ?? [],
                    default => $default ?? [],
                };
            });

        // Act
        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Assert - Should use defaults
        $this->assertSame('array', $cacheConfig->getDefaultStore());
        $this->assertSame('native', $cacheConfig->getDefaultSerializer());
    }

    /**
     * Tests handling of non-array configuration.
     */
    public function testHandlesNonArrayConfiguration(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'cache' => 'invalid_config', // Non-array value
                    'cache.default' => $default ?? 'array',
                    'cache.serializer' => $default ?? 'native',
                    'cache.stores' => $default ?? [],
                    default => $default ?? [],
                };
            });

        // Act
        $cacheConfig = new CacheConfig($this->configuratorMock);

        // Assert - Should use defaults
        $this->assertSame('array', $cacheConfig->getDefaultStore());
        $this->assertSame('native', $cacheConfig->getDefaultSerializer());
    }
}
