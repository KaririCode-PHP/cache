<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Builder;

use KaririCode\Cache\Builder\CacheBuilder;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Decorator\EncryptedCacheDecorator;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\HealthCheckService;
use KaririCode\Cache\Service\LockService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerFactory;
use KaririCode\Logging\LoggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the CacheBuilder class.
 *
 * This test suite verifies that the CacheBuilder correctly constructs and configures
 * Cache instances under various scenarios, including store selection, encryption,
 * and dependency injection.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(CacheBuilder::class)]
final class CacheBuilderTest extends TestCase
{
    /**
     * @var MockObject|ConfigurationManager a mock for the configuration manager
     */
    private MockObject|ConfigurationManager $configuratorMock;

    /**
     * Sets up the test environment before each test.
     */
    protected function setUp(): void
    {
        $this->configuratorMock = $this->createMock(ConfigurationManager::class);
    }

    /**
     * Creates a mock CacheConfig object from a given data array.
     *
     * @param array<string, mixed> $configData the configuration data to simulate
     *
     * @return CacheConfig a configured mock instance
     */
    private function createCacheConfig(array $configData): CacheConfig
    {
        $this->configuratorMock->method('has')->with('cache')->willReturn(true);
        $this->configuratorMock->method('get')->willReturnCallback(function (string $key, $default = null) use ($configData) {
            if ('cache' === $key) {
                return $configData;
            }
            $parts = explode('.', $key);
            array_shift($parts); // Remove the initial 'cache' part
            $value = $configData;
            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }

            return $value;
        });

        return new CacheConfig($this->configuratorMock);
    }

    /**
     * Tests that the builder creates a default cache instance when no specific store is requested.
     */
    public function testBuildCreatesCacheInstanceWithDefaults(): void
    {
        // Arrange
        $config = $this->createCacheConfig([
            'stores' => ['default' => ['driver' => 'array']],
            'default' => 'default',
        ]);
        $builder = new CacheBuilder($config);

        // Act
        $cache = $builder->build();

        // Assert
        $this->assertInstanceOf(Cache::class, $cache);
        $this->assertSame('default', $cache->getName());
    }

    /**
     * Tests that the `forStore` method correctly selects a non-default store.
     * This test uses a mock DriverManager to avoid an actual Redis connection.
     */
    public function testForStoreSelectsCorrectStore(): void
    {
        // Arrange:
        // 1. Define a configuration with a 'redis' store.
        $config = $this->createCacheConfig([
            'stores' => [
                'default' => ['driver' => 'array'],
                'redis' => ['driver' => 'redis', 'config' => ['host' => 'localhost']],
            ],
        ]);

        // 2. Create a mock CacheDriver. It doesn't need to do anything.
        $mockDriver = $this->createMock(CacheDriver::class);

        // 3. Create a mock DriverManager.
        $mockDriverManager = $this->createMock(DriverManager::class);

        // 4. Instruct the mock DriverManager: when `createDriver` is called for 'redis',
        //    return our mock driver instead of attempting a real connection.
        $mockDriverManager->expects($this->once())
            ->method('createDriver')
            ->with('redis', $this->anything()) // Assert that the 'redis' driver is requested.
            ->willReturn($mockDriver); // Return the mock, preventing a network call.

        // 5. Create the builder and inject our mock DriverManager.
        $builder = new CacheBuilder($config);
        $builder->withDriverManager($mockDriverManager);

        // Act:
        // When `build()` is called, it will use our mock DriverManager internally.
        $cache = $builder->forStore('redis')->build();

        // Assert:
        // The Cache object should be created successfully with the correct store name.
        $this->assertInstanceOf(Cache::class, $cache);
        $this->assertSame('redis', $cache->getName());
    }

    /**
     * Tests that the builder throws a ConfigurationException when an unknown store is requested.
     */
    public function testForStoreThrowsExceptionForUnknownStore(): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Cache store 'unknown_store' is not configured");

        // Arrange
        $config = $this->createCacheConfig(['stores' => ['default' => ['driver' => 'array']]]);
        $builder = new CacheBuilder($config);

        // Act
        $builder->forStore('unknown_store');
    }

    /**
     * Tests that the builder correctly applies the EncryptedCacheDecorator when encryption is enabled.
     */
    public function testBuildAppliesEncryptionDecoratorWhenEnabled(): void
    {
        // Arrange
        $config = $this->createCacheConfig([
            'stores' => ['default' => ['driver' => 'array']],
            'default' => 'default',
            'features' => [
                'encryption_enabled' => true,
                // A valid 32-byte key is required for AES-256-GCM.
                'encryption_key' => 'f9a7e6b8c1d0f3e2a9b8c7d6e5f4a3b2',
            ],
        ]);
        $builder = new CacheBuilder($config);

        // Act
        $cache = $builder->build();

        // Assert
        $this->assertInstanceOf(EncryptedCacheDecorator::class, $cache);
    }

    /**
     * Tests that the builder throws an exception if encryption is enabled but no key is provided.
     */
    public function testBuildThrowsExceptionWhenEncryptionKeyIsMissing(): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Encryption key is required but not provided.');

        // Arrange
        $config = $this->createCacheConfig([
            'stores' => ['default' => ['driver' => 'array']],
            'default' => 'default',
            'features' => ['encryption_enabled' => true, 'encryption_key' => null],
        ]);
        $builder = new CacheBuilder($config);

        // Act
        $builder->build();
    }

    /**
     * Tests that all "with" methods return the builder instance for fluent chaining.
     *
     * @param string $method the name of the "with" method to test
     * @param class-string $dependencyClass the class name of the dependency to mock
     */
    #[DataProvider('fluentSetterProvider')]
    public function testFluentSetters(string $method, string $dependencyClass): void
    {
        // Arrange
        $config = $this->createCacheConfig(['stores' => ['default' => ['driver' => 'array']], 'default' => 'default']);
        $builder = new CacheBuilder($config);
        $dependency = $this->createMock($dependencyClass);

        // Act
        $result = $builder->{$method}($dependency);

        // Assert
        $this->assertSame($builder, $result);
    }

    /**
     * Provides data for testing the fluent setter methods.
     *
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function fluentSetterProvider(): array
    {
        return [
            'withDriverManager' => ['withDriverManager', DriverManager::class],
            'withLogger' => ['withLogger', Logger::class],
            'withLoggerRegistry' => ['withLoggerRegistry', LoggerRegistry::class],
            'withLoggerFactory' => ['withLoggerFactory', LoggerFactory::class],
            'withEventDispatcher' => ['withEventDispatcher', EventDispatcherInterface::class],
            'withValidationService' => ['withValidationService', ValidationService::class],
            'withSerializationService' => ['withSerializationService', SerializationService::class],
            'withMetricsService' => ['withMetricsService', MetricsService::class],
            'withEventService' => ['withEventService', EventService::class],
            'withHealthCheckService' => ['withHealthCheckService', HealthCheckService::class],
            'withLockService' => ['withLockService', LockService::class],
            'withExpirationProvider' => ['withExpirationProvider', ExpirationProvider::class],
        ];
    }
}
