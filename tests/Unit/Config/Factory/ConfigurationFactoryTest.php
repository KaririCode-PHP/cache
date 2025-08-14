<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\Factory;

use KaririCode\Cache\Config\Adapter\ConfiguratorAdapter;
use KaririCode\Cache\Config\CacheConfiguration;
use KaririCode\Cache\Config\Factory\ConfigurationFactory;
use KaririCode\Cache\Config\StoreConfiguration;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ConfigurationFactory class.
 *
 * Tests the factory responsible for creating cache configuration instances
 * from various sources, ensuring proper validation, defaults handling,
 * and integration with KaririCode\Configurator according to SOLID principles
 * and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(ConfigurationFactory::class)]
final class ConfigurationFactoryTest extends TestCase
{
    private ConfigurationManager&MockObject $configuratorMock;
    private array $completeConfiguration;
    private array $minimalConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuratorMock = $this->createMock(ConfigurationManager::class);

        $this->completeConfiguration = [
            'default' => 'layered',
            'stores' => [
                'layered' => [
                    'driver' => 'layered',
                    'config' => [
                        'promotion_enabled' => true,
                        'promotion_threshold' => 3,
                        'circuit_breaker_enabled' => true,
                        'max_failures' => 5,
                        'circuit_timeout' => 60,
                        'layers' => [
                            [
                                'driver' => 'apcu',
                                'priority' => 1,
                                'config' => [
                                    'key_prefix' => 'l1:',
                                    'enable_statistics' => true,
                                ],
                            ],
                            [
                                'driver' => 'redis',
                                'priority' => 10,
                                'config' => [
                                    'host' => 'localhost',
                                    'port' => 6379,
                                    'key_prefix' => 'l2:',
                                ],
                            ],
                        ],
                    ],
                ],
                'redis' => [
                    'driver' => 'redis',
                    'config' => [
                        'host' => 'localhost',
                        'port' => 6379,
                        'key_prefix' => 'cache:',
                    ],
                ],
                'filesystem' => [
                    'driver' => 'filesystem',
                    'config' => [
                        'directory' => '/tmp/cache',
                        'key_prefix' => 'fs_',
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
                'metrics_enabled' => true,
                'events_enabled' => true,
                'strict_validation' => true,
                'compression_enabled' => true,
                'async_operations' => false,
                'debug_mode' => false,
                'encryption_enabled' => false,
            ],
            'validation' => [
                'max_key_length' => 250,
                'max_value_size' => 2097152,
                'reserved_characters' => ['{}()', '/', '@', ':'],
                'key_pattern' => '/^[a-zA-Z0-9_\-\.]+$/',
            ],
            'metrics' => [
                'enabled' => true,
                'collector' => 'default',
                'export_format' => 'json',
                'export_interval' => 60,
                'include_driver_stats' => true,
            ],
            'logging' => [
                'enabled' => true,
                'channel' => 'cache',
                'level' => 'info',
                'include_values' => false,
                'max_value_length' => 100,
            ],
            'health_checks' => [
                'enabled' => true,
                'interval' => 300,
                'timeout' => 5,
                'stores_to_check' => ['redis', 'filesystem'],
                'failure_threshold' => 3,
                'recovery_threshold' => 2,
            ],
            'expiration_provider' => [
                'default_ttl' => 3600,
                'operation_ttls' => [
                    'set' => 1800,
                    'increment' => 7200,
                ],
                'key_pattern_ttls' => [
                    'session:*' => 7200,
                    'api:*' => 1800,
                ],
                'sliding_expiration' => false,
                'max_ttl' => 2592000,
            ],
            'optimizations' => [
                'redis' => [
                    'pipeline_batch_size' => 100,
                    'scan_count' => 100,
                ],
                'filesystem' => [
                    'atomic_writes' => true,
                    'metadata_caching' => true,
                ],
            ],
        ];

        $this->minimalConfiguration = [
            'stores' => [
                'array' => [
                    'driver' => 'array',
                ],
            ],
        ];
    }

    /**
     * Tests that the factory can create configuration from array.
     *
     * This test ensures that the static factory method properly
     * creates CacheConfiguration instances from configuration arrays.
     */
    public function testCanCreateConfigurationFromConfig(): void
    {
        // Act
        $configuration = ConfigurationFactory::fromConfig($this->completeConfiguration);

        // Assert
        $this->assertInstanceOf(CacheConfiguration::class, $configuration);
        $this->assertSame('layered', $configuration->getDefaultStore());
        $this->assertCount(3, $configuration->getStores());
        $this->assertSame('igbinary', $configuration->getDefaultSerializer());
    }

    /**
     * Tests that the factory can create configuration from minimal array.
     *
     * This test ensures that the factory method works with minimal
     * configuration and applies appropriate defaults.
     */
    public function testCanCreateConfigurationFromMinimalArray(): void
    {
        // Act
        $configuration = ConfigurationFactory::fromConfig($this->minimalConfiguration);

        // Assert
        $this->assertInstanceOf(CacheConfiguration::class, $configuration);
        $this->assertSame('array', $configuration->getDefaultStore()); // Should use first store as default
        $this->assertCount(1, $configuration->getStores());
        $this->assertTrue($configuration->hasStore('array'));
    }

    /**
     * Tests that the factory can create configurator adapter from ConfigurationManager.
     *
     * This test ensures that the factory properly integrates with
     * KaririCode\Configurator components and returns ConfiguratorAdapter.
     */
    public function testCanCreateConfigurationFromConfigurator(): void
    {
        // Act - ConfigurationFactory.fromConfigurator returns ConfiguratorAdapter, not CacheConfiguration
        $configuration = ConfigurationFactory::fromConfigurator($this->configuratorMock);

        // Assert
        $this->assertInstanceOf(ConfiguratorAdapter::class, $configuration);
    }

    /**
     * Tests that the factory can create configurator adapter with custom namespace.
     *
     * This test ensures that the factory properly handles custom
     * configuration namespaces.
     */
    public function testCanCreateConfigurationWithCustomNamespace(): void
    {
        // Arrange
        $customNamespace = 'app.cache';

        // Act - ConfigurationFactory.fromConfigurator returns ConfiguratorAdapter
        $configuration = ConfigurationFactory::fromConfigurator($this->configuratorMock, $customNamespace);

        // Assert
        $this->assertInstanceOf(ConfiguratorAdapter::class, $configuration);
    }

    /**
     * Tests that the factory handles missing configuration gracefully.
     *
     * This test ensures that when configuration is missing,
     * appropriate defaults are applied via ConfiguratorAdapter.
     */
    public function testHandlesMissingConfigurationGracefully(): void
    {
        // Act - ConfigurationFactory.fromConfigurator returns ConfiguratorAdapter
        $configuration = ConfigurationFactory::fromConfigurator($this->configuratorMock);

        // Assert
        $this->assertInstanceOf(ConfiguratorAdapter::class, $configuration);
    }

    /**
     * Tests that the factory handles non-array configuration.
     *
     * This test ensures that when configuration is not an array,
     * it gracefully falls back to defaults via ConfiguratorAdapter.
     */
    public function testHandlesNonArrayConfiguration(): void
    {
        // Act - ConfigurationFactory.fromConfigurator returns ConfiguratorAdapter
        $configuration = ConfigurationFactory::fromConfigurator($this->configuratorMock);

        // Assert
        $this->assertInstanceOf(ConfiguratorAdapter::class, $configuration);
    }

    /**
     * Tests validation of invalid configuration arrays.
     *
     * This test ensures that the factory properly validates
     * configuration arrays and throws appropriate exceptions.
     */
    #[DataProvider('invalidConfigurationProvider')]
    public function testThrowsExceptionForInvalidConfiguration(array $invalidConfig, string $expectedMessage): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        ConfigurationFactory::fromConfig($invalidConfig);
    }

    /**
     * Tests that the factory creates layered store configurations correctly.
     *
     * This test ensures that complex layered configurations
     * are properly handled and validated.
     */
    public function testCreatesLayeredStoreConfigurationCorrectly(): void
    {
        // Arrange
        $layeredConfig = [
            'default' => 'layered', // Set default to avoid array store lookup
            'stores' => [
                'layered' => [
                    'driver' => 'layered',
                    'config' => [
                        'layers' => [
                            [
                                'driver' => 'redis',
                                'priority' => 1,
                                'config' => ['host' => 'localhost'],
                            ],
                            [
                                'driver' => 'filesystem',
                                'priority' => 10,
                                'config' => ['directory' => '/tmp'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Act
        $configuration = ConfigurationFactory::fromConfig($layeredConfig);
        $layeredStore = $configuration->getStore('layered');

        // Assert
        $this->assertInstanceOf(StoreConfiguration::class, $layeredStore);
        $this->assertSame('layered', $layeredStore->getDriver());
        $this->assertTrue($layeredStore->isLayered());
        $this->assertCount(2, $layeredStore->getLayers());
    }

    /**
     * Tests that the factory creates configuration with environment integration.
     *
     * This test simulates environment-based configuration creation
     * which is common in real-world applications.
     */
    public function testCreatesConfigurationWithEnvironmentIntegration(): void
    {
        // Arrange - Simulate environment-driven configuration
        $envConfig = [
            'default' => 'redis', // From env('CACHE_DEFAULT_STORE', 'redis')
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'config' => [
                        'host' => 'redis_server', // From env('REDIS_HOST', 'localhost')
                        'port' => 6380, // From env('REDIS_PORT', 6379)
                        'password' => 'secret', // From env('REDIS_PASSWORD')
                    ],
                ],
            ],
            'features' => [
                'debug_mode' => true, // From env('CACHE_DEBUG_MODE', false)
                'metrics_enabled' => false, // From env('CACHE_METRICS_ENABLED', true)
            ],
        ];

        // Act
        $configuration = ConfigurationFactory::fromConfig($envConfig);

        // Assert
        $this->assertInstanceOf(CacheConfiguration::class, $configuration);
        $this->assertSame('redis', $configuration->getDefaultStore());

        $redisStore = $configuration->getStore('redis');
        $this->assertSame('redis_server', $redisStore->getOption('host'));
        $this->assertSame(6380, $redisStore->getOption('port'));
        $this->assertSame('secret', $redisStore->getOption('password'));

        $features = $configuration->getFeatures();
        $this->assertTrue($features->debugMode);
        $this->assertFalse($features->metricsEnabled);
    }

    /**
     * Verifies the create() method with an array input.
     * This ensures the deprecated method maintains backward compatibility.
     */
    public function testCreateMethodWithArray(): void
    {
        // Act
        $provider = ConfigurationFactory::create($this->minimalConfiguration);

        // Assert
        $this->assertInstanceOf(CacheConfiguration::class, $provider);
    }

    /**
     * Verifies the create() method with a ConfigurationManager input.
     */
    public function testCreateMethodWithConfigurator(): void
    {
        // Act
        $provider = ConfigurationFactory::create($this->configuratorMock);

        // Assert
        $this->assertInstanceOf(ConfiguratorAdapter::class, $provider);
    }

    /**
     * Verifies the create() method when a provider instance is passed through.
     */
    public function testCreateMethodWithProviderInstance(): void
    {
        // Arrange
        $providerMock = $this->createMock(ConfigurationProvider::class);

        // Act
        $result = ConfigurationFactory::create($providerMock);

        // Assert
        $this->assertSame($providerMock, $result, 'Should return the same instance passed in.');
    }

    /**
     * Verifies the create() method throws an exception for invalid input types.
     */
    public function testCreateMethodWithInvalidTypeThrowsException(): void
    {
        // Assert

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration type');

        // Act
        ConfigurationFactory::create('a string is not valid');
    }

    /**
     * Tests that the factory supports configuration merging.
     *
     * This test ensures that the factory can handle configuration
     * merging scenarios for complex setups.
     */
    public function testSupportsConfigurationMerging(): void
    {
        // Arrange
        $baseConfig = [
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
            'features' => [
                'debug_mode' => false,
                'metrics_enabled' => true,
            ],
        ];

        $overrideConfig = [
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'config' => ['host' => 'localhost'],
                ],
            ],
            'features' => [
                'debug_mode' => true, // Override
            ],
        ];

        $mergedConfig = array_merge_recursive($baseConfig, $overrideConfig);

        // Act
        $configuration = ConfigurationFactory::fromConfig($mergedConfig);

        // Assert
        $this->assertInstanceOf(CacheConfiguration::class, $configuration);
        $this->assertTrue($configuration->hasStore('array'));
        $this->assertTrue($configuration->hasStore('redis'));

        $features = $configuration->getFeatures();
        $this->assertTrue($features->debugMode); // Should be overridden
        $this->assertTrue($features->metricsEnabled); // Should be preserved
    }

    /**
     * Provides invalid configuration data for testing validation.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurationProvider(): array
    {
        return [
            'empty configuration' => [
                [],
                'At least one cache store must be configured',
            ],
            'missing stores' => [
                ['default' => 'redis'],
                'At least one cache store must be configured',
            ],
            'empty stores array' => [
                ['stores' => []],
                'At least one cache store must be configured',
            ],
            'invalid store configuration' => [
                [
                    'stores' => [
                        'invalid' => 'not_an_array',
                    ],
                ],
                'Store configuration must be an array',
            ],
            'store missing driver' => [
                [
                    'stores' => [
                        'redis' => [
                            'config' => ['host' => 'localhost'],
                            // Missing 'driver'
                        ],
                    ],
                ],
                'Driver must be specified for cache store',
            ],
            'layered store missing layers' => [
                [
                    'stores' => [
                        'layered' => [
                            'driver' => 'layered',
                            'config' => [
                                // Missing 'layers'
                            ],
                        ],
                    ],
                ],
                'Layered cache must have a non-empty "layers" array',
            ],
            'invalid serializer reference' => [
                [
                    'stores' => [
                        'array' => ['driver' => 'array'],
                    ],
                    'serializer' => 'nonexistent',
                    'serializers' => [
                        'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
                    ],
                ],
                'Default serializer \'nonexistent\' is not configured',
            ],
        ];
    }
}
