<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\Adapter;

use KaririCode\Cache\Config\Adapter\ConfiguratorAdapter;
use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Config\DTO\ValidationConfig;
use KaririCode\Cache\Config\StoreConfiguration;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Configurator\Contract\Configurator\ConfigurationManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguratorAdapter::class)]
final class ConfiguratorAdapterTest extends TestCase
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
                'layered' => [
                    'driver' => 'layered',
                    'config' => [
                        'promotion_enabled' => true,
                        'promotion_threshold' => 3,
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
                'compression_enabled' => false,
                'async_operations' => false,
                'debug_mode' => true,
                'encryption_enabled' => false,
            ],
            'validation' => [
                'max_key_length' => 200,
                'max_value_size' => 1048576,
                'reserved_characters' => ['{}()', '/', '@'],
                'key_pattern' => '/^[a-zA-Z0-9_\-]+$/',
            ],
        ];
    }

    public function testCanBeCreatedWithDefaultNamespace(): void
    {
        $adapter = new ConfiguratorAdapter($this->configuratorMock);
        $this->assertInstanceOf(ConfiguratorAdapter::class, $adapter);
    }

    public function testCanBeCreatedWithCustomNamespace(): void
    {
        $adapter = new ConfiguratorAdapter($this->configuratorMock, 'app.cache');
        $this->assertInstanceOf(ConfiguratorAdapter::class, $adapter);
    }

    public function testProperlyDelegatesConfigurationAccess(): void
    {
        $this->setupConfiguratorExpectations('cache');
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $this->assertSame('redis', $adapter->getDefaultStore());
        $this->assertSame('igbinary', $adapter->getDefaultSerializer());

        $stores = $adapter->getStores();
        $this->assertIsArray($stores);
        $this->assertCount(3, $stores);
        $this->assertArrayHasKey('redis', $stores);
        $this->assertArrayHasKey('filesystem', $stores);
        $this->assertArrayHasKey('layered', $stores);

        foreach ($stores as $store) {
            $this->assertInstanceOf(StoreConfiguration::class, $store);
        }
    }

    public function testProperlyHandlesStoreRetrieval(): void
    {
        $this->setupConfiguratorExpectations('cache');
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $redisStore = $adapter->getStore('redis');
        $layeredStore = $adapter->getStore('layered');

        $this->assertInstanceOf(StoreConfiguration::class, $redisStore);
        $this->assertSame('redis', $redisStore->getName());
        $this->assertSame('redis', $redisStore->getDriver());
        $this->assertSame('localhost', $redisStore->getOption('host'));
        $this->assertSame(6379, $redisStore->getOption('port'));

        $this->assertInstanceOf(StoreConfiguration::class, $layeredStore);
        $this->assertSame('layered', $layeredStore->getName());
        $this->assertSame('layered', $layeredStore->getDriver());
        $this->assertTrue($layeredStore->isLayered());
        $this->assertCount(2, $layeredStore->getLayers());
    }

    public function testProperlyHandlesStoreExistenceChecks(): void
    {
        $this->setupConfiguratorExpectations('cache');
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $this->assertTrue($adapter->hasStore('redis'));
        $this->assertTrue($adapter->hasStore('filesystem'));
        $this->assertTrue($adapter->hasStore('layered'));
        $this->assertFalse($adapter->hasStore('nonexistent'));
    }

    public function testProperlyDelegatesDtoConfigurations(): void
    {
        $this->setupConfiguratorExpectations('cache');
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $features = $adapter->getFeatures();
        $this->assertInstanceOf(FeatureConfig::class, $features);
        $this->assertTrue($features->stampedeProtection);
        $this->assertTrue($features->metricsEnabled);
        $this->assertTrue($features->eventsEnabled);
        $this->assertTrue($features->strictValidation);
        $this->assertFalse($features->compressionEnabled);
        $this->assertFalse($features->asyncOperations);
        $this->assertTrue($features->debugMode);
        $this->assertFalse($features->encryptionEnabled);

        $validation = $adapter->getValidation();
        $this->assertInstanceOf(ValidationConfig::class, $validation);
        $this->assertSame(200, $validation->maxKeyLength);
        $this->assertSame(1048576, $validation->maxValueSize);
        $this->assertSame(['{}()', '/', '@'], $validation->reservedCharacters);
        $this->assertSame('/^[a-zA-Z0-9_\-]+$/', $validation->keyPattern);
    }

    public function testHandlesMissingConfigurationGracefully(): void
    {
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with('cache')
            ->willReturn(false);

        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('At least one cache store must be configured');

        $adapter->getDefaultStore();
    }

    public function testHandlesInvalidConfigurationFormat(): void
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
            ->willReturn('invalid_configuration');

        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('At least one cache store must be configured');

        $adapter->getDefaultStore();
    }

    public function testThrowsExceptionForNonExistentStore(): void
    {
        $this->setupConfiguratorExpectations('cache');
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Cache store \'nonexistent\' is not configured');

        $adapter->getStore('nonexistent');
    }

    public function testCachesConfigurationAfterFirstLoad(): void
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
            ->willReturn($this->sampleConfiguration);

        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $defaultStore1 = $adapter->getDefaultStore();
        $defaultStore2 = $adapter->getDefaultStore();
        $stores1 = $adapter->getStores();
        $stores2 = $adapter->getStores();

        $this->assertSame($defaultStore1, $defaultStore2);
        $this->assertSame($stores1, $stores2);
    }

    public function testImplementsRequiredInterfaces(): void
    {
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $this->assertInstanceOf(\KaririCode\Cache\Contract\Configuration\ConfigurationProvider::class, $adapter);
        $this->assertInstanceOf(\KaririCode\Contract\ImmutableValue::class, $adapter);
    }

    public function testHandlesSerializerConfigurationCorrectly(): void
    {
        $this->setupConfiguratorExpectations('cache');
        $adapter = new ConfiguratorAdapter($this->configuratorMock);

        $serializers = $adapter->getSerializers();
        $defaultSerializer = $adapter->getDefaultSerializer();

        $this->assertIsArray($serializers);
        $this->assertArrayHasKey('native', $serializers);
        $this->assertArrayHasKey('igbinary', $serializers);
        $this->assertArrayHasKey('json', $serializers);
        $this->assertSame('igbinary', $defaultSerializer);

        $this->assertSame(\KaririCode\Cache\Serializer\NativeSerializer::class, $serializers['native']);
        $this->assertSame(\KaririCode\Cache\Serializer\IgbinarySerializer::class, $serializers['igbinary']);
        $this->assertSame(\KaririCode\Cache\Serializer\JsonSerializer::class, $serializers['json']);
    }

    private function setupConfiguratorExpectations(string $namespace): void
    {
        $this->configuratorMock
            ->expects($this->once())
            ->method('has')
            ->with($namespace)
            ->willReturn(true);

        $this->configuratorMock
            ->expects($this->once())
            ->method('get')
            ->with($namespace)
            ->willReturn($this->sampleConfiguration);
    }
}
