<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config;

use KaririCode\Cache\Config\CacheConfiguration;
use KaririCode\Cache\Config\StoreConfiguration;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CacheConfiguration class.
 *
 * Tests the primary immutable configuration container that implements the
 * Value Object pattern, ensuring proper validation, defaults handling,
 * and adherence to SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(CacheConfiguration::class)]
final class CacheConfigurationTest extends TestCase
{
    /**
     * Tests that the configuration can be created with minimal valid config.
     *
     * This test ensures that the constructor properly handles basic
     * configuration with required elements.
     */
    public function testCanBeCreatedWithMinimalValidConfig(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'array' => [
                    'driver' => 'array',
                ],
            ],
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);

        // Assert
        $this->assertSame('array', $cacheConfig->getDefaultStore());
        $this->assertCount(1, $cacheConfig->getStores());
        $this->assertTrue($cacheConfig->hasStore('array'));
        $this->assertSame('native', $cacheConfig->getDefaultSerializer());
    }

    /**
     * Tests that the first store is used as default when only one store is configured
     * and no explicit default is set. This covers an untested branch.
     */
    public function testUsesFirstStoreAsDefaultWhenOnlyOneExists(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'redis' => ['driver' => 'redis'], // The only store
            ],
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);

        // Assert
        $this->assertSame('redis', $cacheConfig->getDefaultStore());
    }

    /**
     * Tests that the first available store is used as a final fallback when multiple
     * stores exist, none are named 'array', and no default is set.
     * This covers the final branch of the determineDefaultStore method.
     */
    public function testUsesFirstAvailableStoreAsDefaultAsLastResort(): void
    {
        // Arrange: A config with multiple stores, none named 'array', and no 'default'.
        $config = [
            'stores' => [
                'redis' => ['driver' => 'redis'],
                'memcached' => ['driver' => 'memcached'],
            ],
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);

        // Assert: The first key from the stores array ('redis') should be chosen.
        $this->assertSame('redis', $cacheConfig->getDefaultStore());
    }

    /**
     * Tests that the 'array' store is preferred as the default when multiple stores exist
     * and no explicit default is set. This covers the final untested branch.
     */
    public function testPrefersArrayStoreAsDefaultWhenAvailable(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'redis' => ['driver' => 'redis'],
                'array' => ['driver' => 'array'],
                'memcached' => ['driver' => 'memcached'],
            ],
            // No 'default' key is specified
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);

        // Assert
        $this->assertSame('array', $cacheConfig->getDefaultStore());
    }

    /**
     * Tests that the configuration can be created with full configuration.
     *
     * This test verifies that all configuration options are properly
     * handled and DTOs are correctly instantiated.
     */
    public function testCanBeCreatedWithFullConfiguration(): void
    {
        // Arrange
        $config = [
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
            'features' => [
                'stampede_protection' => false,
                'debug_mode' => true,
            ],
            'validation' => [
                'max_key_length' => 200,
            ],
            'metrics' => [
                'enabled' => false,
            ],
            'logging' => [
                'level' => 'debug',
            ],
            'health_checks' => [
                'interval' => 120,
            ],
            'expiration_provider' => [
                'default_ttl' => 7200,
            ],
            'optimizations' => [
                'redis' => ['pipeline_batch_size' => 50],
            ],
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);

        // Assert
        $this->assertSame('redis', $cacheConfig->getDefaultStore());
        $this->assertCount(2, $cacheConfig->getStores());
        $this->assertTrue($cacheConfig->hasStore('redis'));
        $this->assertTrue($cacheConfig->hasStore('array'));
        $this->assertSame('json', $cacheConfig->getDefaultSerializer());

        // Test DTOs are properly created
        $features = $cacheConfig->getFeatures();
        $this->assertFalse($features->stampedeProtection);
        $this->assertTrue($features->debugMode);

        $validation = $cacheConfig->getValidation();
        $this->assertSame(200, $validation->maxKeyLength);

        $metrics = $cacheConfig->getMetrics();
        $this->assertFalse($metrics->enabled);

        $logging = $cacheConfig->getLogging();
        $this->assertSame('debug', $logging->level);

        $healthChecks = $cacheConfig->getHealthChecks();
        $this->assertSame(120, $healthChecks->interval);

        $expirationProvider = $cacheConfig->getExpirationProvider();
        $this->assertSame(7200, $expirationProvider->defaultTtl);

        $optimizations = $cacheConfig->getOptimizations();
        $this->assertSame(['pipeline_batch_size' => 50], $optimizations->redis);
    }

    /**
     * Tests getting a specific store configuration.
     *
     * This test ensures that store retrieval works correctly and
     * returns proper StoreConfiguration instances.
     */
    public function testCanGetSpecificStoreConfiguration(): void
    {
        // Arrange - Include default to avoid automatic 'array' lookup
        $config = [
            'default' => 'redis', // Specify redis as default
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'config' => [
                        'host' => 'localhost',
                        'port' => 6379,
                    ],
                ],
            ],
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);
        $redisStore = $cacheConfig->getStore('redis');

        // Assert
        $this->assertInstanceOf(StoreConfiguration::class, $redisStore);
        $this->assertSame('redis', $redisStore->getName());
        $this->assertSame('redis', $redisStore->getDriver());
        $this->assertSame('localhost', $redisStore->getOption('host'));
        $this->assertSame(6379, $redisStore->getOption('port'));
    }

    /**
     * Tests that getting a non-existent store throws an exception.
     *
     * This test ensures proper error handling following the
     * Fail-Fast principle.
     */
    public function testThrowsExceptionWhenGettingNonExistentStore(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ];
        $cacheConfig = new CacheConfiguration($config);

        // Assert - Use correct exception message format
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Cache store \'nonexistent\' is not configured');

        // Act
        $cacheConfig->getStore('nonexistent');
    }

    /**
     * Tests validation of invalid configuration.
     *
     * This test ensures that the constructor properly validates
     * configuration and throws appropriate exceptions.
     */
    #[DataProvider('invalidConfigurationProvider')]
    public function testThrowsExceptionForInvalidConfiguration(array $invalidConfig, string $expectedMessage): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        new CacheConfiguration($invalidConfig);
    }

    /**
     * Provides invalid configuration data for testing validation.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurationProvider(): array
    {
        return [
            'empty stores' => [
                ['stores' => []],
                'At least one cache store must be configured',
            ],
            'missing stores' => [
                [],
                'At least one cache store must be configured',
            ],
            'non-array stores' => [
                ['stores' => 'invalid'],
                'Stores must be an array',
            ],
            'non-string default' => [
                [
                    'default' => 123,
                    'stores' => ['array' => ['driver' => 'array']],
                ],
                'Default store must be a string',
            ],
            'invalid store name type' => [
                [
                    'stores' => [
                        123 => ['driver' => 'array'],
                    ],
                ],
                'Store configuration must be an array',
            ],
            'invalid store config type' => [
                [
                    'stores' => [
                        'array' => 'invalid',
                    ],
                ],
                'Store configuration must be an array',
            ],
        ];
    }

    /**
     * Tests default serializers are properly configured.
     *
     * This test ensures that when no serializers are provided,
     * sensible defaults are used.
     */
    public function testUsesDefaultSerializersWhenNoneProvided(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ];

        // Act
        $cacheConfig = new CacheConfiguration($config);
        $serializers = $cacheConfig->getSerializers();

        // Assert
        $this->assertArrayHasKey('native', $serializers);
        $this->assertArrayHasKey('json', $serializers);
        $this->assertArrayHasKey('igbinary', $serializers);

        $this->assertSame(\KaririCode\Cache\Serializer\NativeSerializer::class, $serializers['native']);
        $this->assertSame(\KaririCode\Cache\Serializer\JsonSerializer::class, $serializers['json']);
        $this->assertSame(\KaririCode\Cache\Serializer\IgbinarySerializer::class, $serializers['igbinary']);
    }

    /**
     * Tests that invalid store configuration in stores array throws exception.
     *
     * This test ensures that individual store validation is properly handled.
     */
    public function testThrowsExceptionForInvalidStoreConfiguration(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'invalid' => [
                    // Missing driver
                    'config' => ['some' => 'config'],
                ],
            ],
        ];

        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('stores.invalid');

        // Act
        new CacheConfiguration($config);
    }

    /**
     * Tests that configuration with non-existent default store throws exception.
     *
     * This test ensures that the default store validation works correctly.
     */
    public function testThrowsExceptionWhenDefaultStoreDoesNotExist(): void
    {
        // Arrange
        $config = [
            'default' => 'nonexistent',
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ];

        // Assert - Use correct exception message format
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Cache store \'nonexistent\' is not configured');

        // Act
        new CacheConfiguration($config);
    }

    /**
     * Tests that configuration with non-existent default serializer throws exception.
     *
     * This test ensures that the default serializer validation works correctly.
     */
    public function testThrowsExceptionWhenDefaultSerializerDoesNotExist(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
            'serializer' => 'nonexistent',
            'serializers' => [
                'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
            ],
        ];

        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Default serializer \'nonexistent\' is not configured');

        // Act
        new CacheConfiguration($config);
    }

    /**
     * Tests that the configuration is truly immutable.
     *
     * This test verifies that all properties are readonly and follow
     * the Value Object pattern correctly.
     */
    public function testIsImmutable(): void
    {
        // Arrange
        $config = [
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ];
        $cacheConfig = new CacheConfiguration($config);

        // Assert - Verify all properties are readonly
        $reflection = new \ReflectionClass($cacheConfig);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }
}
