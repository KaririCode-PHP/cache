<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\Adapter;

use KaririCode\Cache\Config\Adapter\ConfiguratorAdapter;
use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\HealthCheckConfig;
use KaririCode\Cache\Config\DTO\LoggingConfig;
use KaririCode\Cache\Config\DTO\MetricsConfig;
use KaririCode\Cache\Config\DTO\OptimizationConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use KaririCode\Contract\ImmutableValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive unit tests for the ConfiguratorAdapter class.
 *
 * This test suite ensures the adapter correctly bridges between KaririCode\Configurator
 * and the cache configuration system, following SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 *
 * @covers \KaririCode\Cache\Config\Adapter\ConfiguratorAdapter
 */
#[CoversClass(ConfiguratorAdapter::class)]
final class ConfiguratorAdapterTest extends TestCase
{
    private ConfigurationManager&MockObject $configuratorMock;
    private array $completeConfiguration;
    private array $minimalConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuratorMock = $this->createMock(ConfigurationManager::class);
        $this->setupCompleteConfiguration();
        $this->setupMinimalConfiguration();
    }

    /**
     * Tests the static factory method fromConfigurator.
     * This covers the remaining uncovered method, ensuring 100% coverage.
     */
    public function testFromConfiguratorFactoryMethod(): void
    {
        // Act
        // Call the static factory method directly.
        $adapter = ConfiguratorAdapter::fromConfigurator($this->configuratorMock);

        // Assert
        // Verify that it returns a valid instance of the adapter.
        $this->assertInstanceOf(ConfiguratorAdapter::class, $adapter);
    }

    /**
     * Tests successful adapter creation with complete configuration.
     */
    public function testSuccessfulCreationWithCompleteConfiguration(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        // Act
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // FIX: Trigger lazy-loading to ensure the configurator mock is used,
        // satisfying the expectations set in `setupConfiguratorWithData`.
        $defaultStore = $adapter->getDefaultStore();

        // Assert
        $this->assertInstanceOf(ConfigurationProvider::class, $adapter);
        $this->assertInstanceOf(ImmutableValue::class, $adapter);
        $this->assertSame('layered', $defaultStore);
    }

    /**
     * Tests adapter creation with custom namespace.
     */
    public function testCreationWithCustomNamespace(): void
    {
        // Arrange
        $customNamespace = 'app.cache';
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with($customNamespace)
            ->willReturn(true);
        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with($customNamespace)
            ->willReturn($this->completeConfiguration);
        // Act
        $adapter = new ConfiguratorAdapter($this->configuratorMock, $customNamespace);
        // Assert
        $this->assertSame('layered', $adapter->getDefaultStore());
    }

    /**
     * Tests lazy loading behavior - configuration is only loaded when accessed.
     */
    public function testLazyLoadingBehavior(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->never())
            ->method('has');
        $this->configuratorMock
            ->expects($this->never())
            ->method('get');
        // Act - Just create the adapter without accessing any properties
        $adapter = new ConfiguratorAdapter($this->configuratorMock);
        // Assert
        $this->assertInstanceOf(ConfiguratorAdapter::class, $adapter);
    }

    /**
     * Tests configuration caching - should only load once.
     */
    public function testConfigurationCaching(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once()) // Should only be called once
            ->method('has')
            ->with('cache')
            ->willReturn(true);
        $this->configuratorMock
            ->expects($this->once()) // Should only be called once
            ->method('get')
            ->with('cache')
            ->willReturn($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act - Multiple accesses
        $adapter->getDefaultStore();
        $adapter->getStores();
        $adapter->getDefaultSerializer();
        $adapter->getFeatures();
        // Assert - Methods were only called once (implicit in expects)
        $this->assertSame('layered', $adapter->getDefaultStore());
    }

    /**
     * Tests all DTO methods return correct instances.
     */
    public function testAllDtoMethodsReturnCorrectInstances(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act & Assert
        $this->assertInstanceOf(FeatureConfig::class, $adapter->getFeatures());
        $this->assertInstanceOf(ValidationConfig::class, $adapter->getValidation());
        $this->assertInstanceOf(MetricsConfig::class, $adapter->getMetrics());
        $this->assertInstanceOf(LoggingConfig::class, $adapter->getLogging());
        $this->assertInstanceOf(HealthCheckConfig::class, $adapter->getHealthChecks());
        $this->assertInstanceOf(ExpirationProviderConfig::class, $adapter->getExpirationProvider());
        $this->assertInstanceOf(OptimizationConfig::class, $adapter->getOptimizations());
    }

    /**
     * Tests store configuration retrieval.
     */
    public function testStoreConfigRetrieval(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act
        $stores = $adapter->getStores();
        $redisStore = $adapter->getStore('redis');
        $layeredStore = $adapter->getStore('layered');

        // Assert
        $this->assertCount(3, $stores);
        $this->assertInstanceOf(StoreConfig::class, $redisStore);
        $this->assertInstanceOf(StoreConfig::class, $layeredStore);
        $this->assertSame('redis', $redisStore->driver);
        $this->assertSame('layered', $layeredStore->driver);
        $this->assertTrue($adapter->hasStore('redis'));
        $this->assertTrue($adapter->hasStore('filesystem'));
        $this->assertFalse($adapter->hasStore('nonexistent'));
    }

    /**
     * Tests serializer configuration handling.
     */
    public function testSerializerConfiguration(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act
        $serializers = $adapter->getSerializers();
        $defaultSerializer = $adapter->getDefaultSerializer();
        // Assert
        $this->assertIsArray($serializers);
        $this->assertArrayHasKey('native', $serializers);
        $this->assertArrayHasKey('igbinary', $serializers);
        $this->assertArrayHasKey('json', $serializers);
        $this->assertSame('igbinary', $defaultSerializer);
    }

    /**
     * Tests handling of missing configuration namespace.
     */
    public function testHandlesMissingNamespaceGracefully(): void
    {
        // Arrange
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(false);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('At least one cache store must be configured');

        $adapter->getDefaultStore();
    }

    /**
     * Tests handling of invalid configuration format.
     */
    public function testHandlesInvalidConfigurationFormat(): void
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
            ->willReturn('invalid_string_config');
        // Not an array

        $adapter = new ConfiguratorAdapter($this->configuratorMock);
        // Act & Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('At least one cache store must be configured');
        $adapter->getStores();
    }

    /**
     * Tests exception for non-existent store.
     */
    public function testThrowsExceptionForNonExistentStore(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Cache store 'nonexistent' is not configured");

        $adapter->getStore('nonexistent');
    }

    /**
     * Tests default values when configuration sections are missing.
     */
    #[DataProvider('missingConfigurationSectionsProvider')]
    public function testDefaultValuesForMissingSections(
        array $config,
        string $method,
        mixed $expected
    ): void {
        // Arrange
        $this->setupConfiguratorWithData($config);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act
        $result = $adapter->$method();
        // Assert
        if (is_object($expected)) {
            $this->assertInstanceOf(get_class($expected), $result);
        } else {
            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Tests configuration with environment variable substitution.
     */
    public function testConfigurationWithEnvironmentVariables(): void
    {
        // Arrange
        $configWithEnv = [
            'default' => 'redis',
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'config' => [
                        'host' => '${REDIS_HOST:localhost}',
                        'port' => '${REDIS_PORT:6379}',
                    ],
                ],
            ],
        ];
        $this->setupConfiguratorWithData($configWithEnv);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act
        $redisStore = $adapter->getStore('redis');
        // Assert
        $this->assertSame('redis', $redisStore->driver);
        // Note: Actual env var substitution would be handled by Configurator
    }

    /**
     * Tests immutability of the adapter.
     */
    public function testImmutability(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // FIX: Trigger lazy-loading before checking properties to meet mock expectations.
        $adapter->getDefaultStore();

        // Act & Assert
        $reflection = new \ReflectionClass($adapter);
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic()) {
                $this->assertTrue(
                    $property->isReadOnly() || $property->isPrivate(),
                    "Property {$property->getName()} should be readonly or private"
                );
            }
        }
    }

    /**
     * Tests complex layered driver configuration.
     */
    public function testComplexLayeredDriverConfiguration(): void
    {
        // Arrange
        $this->setupConfiguratorWithData($this->completeConfiguration);
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        // Act
        $layeredStore = $adapter->getStore('layered');
        // Assert
        $this->assertCount(2, $layeredStore->layers);
    }

    // Helper methods

    private function setupConfiguratorWithData(array $data): void
    {
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(true);
        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with('cache')
            ->willReturn($data);
    }

    private function setupCompleteConfiguration(): void
    {
        $this->completeConfiguration = [
            'default' => 'layered',
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'config' => [
                        'host' => 'localhost',
                        'port' => 6379,
                        'database' => 1,
                    ],
                ],
                'filesystem' => [
                    'driver' => 'filesystem',
                    'config' => [
                        'directory' => '/tmp/cache',
                    ],
                ],
                'layered' => [
                    'driver' => 'layered',
                    'config' => [
                        'promotion_enabled' => true,
                        'promotion_threshold' => 3,
                        'layers' => [
                            ['driver' => 'apcu', 'priority' => 1],
                            ['driver' => 'redis', 'priority' => 10],
                        ],
                    ],
                ],
            ],
            'serializer' => 'igbinary',
            'serializers' => [
                'native' => \KaririCode\Cache\Serializer\NativeSerializer::class,
                'igbinary' => \KaririCode\Cache\Serializer\IgbinarySerializer::class,
                'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
            ],
            'features' => [
                'stampede_protection' => true,
                'debug_mode' => false,
            ],
            'validation' => [
                'max_key_length' => 250,
                'reserved_characters' => ['/', '@'],
            ],
            'metrics' => [
                'enabled' => true,
                'collector' => 'prometheus',
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'info',
            ],
            'health_checks' => [
                'enabled' => true,
                'interval' => 300,
            ],
            'expiration_provider' => [
                'default_ttl' => 3600,
            ],
            'optimizations' => [
                'redis' => ['pipeline_batch_size' => 100],
            ],
        ];
    }

    private function setupMinimalConfiguration(): void
    {
        $this->minimalConfiguration = [
            'stores' => [
                'array' => [
                    'driver' => 'array',
                ],
            ],
        ];
    }

    /**
     * Data provider for missing configuration sections test.
     */
    public static function missingConfigurationSectionsProvider(): array
    {
        $minConfig = [
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ];

        return [
            'missing features' => [
                $minConfig,
                'getFeatures',
                FeatureConfig::fromConfig([]),
            ],
            'missing validation' => [
                $minConfig,
                'getValidation',
                ValidationConfig::fromConfig([]),
            ],
            'missing metrics' => [
                $minConfig,
                'getMetrics',
                MetricsConfig::fromConfig([]),
            ],
            'missing logging' => [
                $minConfig,
                'getLogging',
                LoggingConfig::fromConfig([]),
            ],
            'missing health checks' => [
                $minConfig,
                'getHealthChecks',
                HealthCheckConfig::fromConfig([]),
            ],
            'missing expiration provider' => [
                $minConfig,
                'getExpirationProvider',
                ExpirationProviderConfig::fromConfig([]),
            ],
            'missing optimizations' => [
                $minConfig,
                'getOptimizations',
                OptimizationConfig::fromConfig([]),
            ],
        ];
    }
}
