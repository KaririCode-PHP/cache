<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Config\DTO\OptimizationConfig;
use KaririCode\Cache\Config\DTO\StoreConfig;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\Configuration\ConfigurationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Service\CacheManager;
use KaririCode\Contract\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CacheManager class.
 *
 * Tests all aspects of cache store management including configuration
 * delegation, driver creation, error handling, and SOLID principles compliance.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(CacheManager::class)]
final class CacheManagerTest extends TestCase
{
    private MockObject|ConfigurationProvider $configurationMock;
    private MockObject|DriverManager $driverManagerMock;
    private MockObject|Logger $loggerMock;

    /**
     * Sets up the test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->configurationMock = $this->createMock(ConfigurationProvider::class);
        $this->driverManagerMock = $this->createMock(DriverManager::class);
        $this->loggerMock = $this->createMock(Logger::class);
    }

    /**
     * Creates an instance of CacheManager with mocked dependencies.
     */
    private function createManager(): CacheManager
    {
        // Mock the return of configuration DTOs
        $this->configurationMock->method('getSerializers')->willReturn([]);
        $this->configurationMock->method('getDefaultSerializer')->willReturn('native');
        $this->configurationMock->method('getExpirationProvider')->willReturn(ExpirationProviderConfig::fromConfig());
        $this->configurationMock->method('getOptimizations')->willReturn(OptimizationConfig::fromConfig());

        // Inject the mocked DriverManager
        return new CacheManager(
            $this->configurationMock,
            $this->loggerMock,
            $this->driverManagerMock
        );
    }

    /**
     * Tests that the manager can retrieve a specific store instance.
     */
    public function testCanGetSpecificStore(): void
    {
        // Arrange
        $storeConfig = StoreConfig::fromConfig(['driver' => 'redis']);
        $driverMock = $this->createMock(CacheDriver::class);

        $this->configurationMock->method('getStore')->with('redis')->willReturn($storeConfig);
        $this->driverManagerMock->method('createDriver')->with('redis')->willReturn($driverMock);

        $manager = $this->createManager();

        // Act
        $store = $manager->store('redis');

        // Assert
        $this->assertSame($driverMock, $store);
    }

    /**
     * Tests that the manager retrieves the default store when no name is provided.
     */
    public function testCanGetDefaultStore(): void
    {
        // Arrange
        $driverMock = $this->createMock(CacheDriver::class);
        $this->setupDefaultStore($driverMock);
        $manager = $this->createManager();

        // Act
        $store = $manager->store();
        $defaultStore = $manager->getDefaultStore();

        // Assert
        $this->assertSame($driverMock, $store);
        $this->assertSame($store, $defaultStore);
    }

    /**
     * Tests that store instances are cached after first creation to avoid redundant work.
     */
    public function testStoresAreCachedAfterCreation(): void
    {
        // Arrange
        $storeConfig = StoreConfig::fromConfig(['driver' => 'redis']);
        $driverMock = $this->createMock(CacheDriver::class);

        $this->configurationMock
            ->expects($this->once()) // Crucial: getStore is only called once
            ->method('getStore')
            ->with('redis')
            ->willReturn($storeConfig);

        $this->driverManagerMock
            ->expects($this->once()) // Crucial: createDriver is only called once
            ->method('createDriver')
            ->with('redis')
            ->willReturn($driverMock);

        $manager = $this->createManager();

        // Act
        $store1 = $manager->store('redis');
        $store2 = $manager->store('redis'); // This should hit the instance cache

        // Assert
        $this->assertSame($store1, $store2);
    }

    /**
     * Tests that requesting a non-existent store throws a ConfigurationException.
     */
    public function testThrowsExceptionForNonExistentStore(): void
    {
        // Arrange
        $this->configurationMock
            ->method('getStore')
            ->with('nonexistent')
            ->willThrowException(ConfigurationException::missingStore('nonexistent'));

        $manager = $this->createManager();

        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Cache store 'nonexistent' is not configured");

        // Act
        $manager->store('nonexistent');
    }

    /**
     * Tests the delegation of basic cache operations to the default store.
     */
    public function testDelegatesBasicOperationsToDefaultStore(): void
    {
        // Arrange
        $driverMock = $this->createMock(CacheDriver::class);
        $this->setupDefaultStore($driverMock);
        $manager = $this->createManager();

        $driverMock->expects($this->once())->method('get')->with('key1', 'default')->willReturn('value1');
        $driverMock->expects($this->once())->method('set')->with('key2', 'value2', 3600)->willReturn(true);
        $driverMock->expects($this->once())->method('delete')->with('key3')->willReturn(true);
        $driverMock->expects($this->once())->method('has')->with('key4')->willReturn(true);
        $driverMock->expects($this->once())->method('clear')->willReturn(true);

        // Act & Assert
        $this->assertSame('value1', $manager->get('key1', 'default'));
        $this->assertTrue($manager->set('key2', 'value2', 3600));
        $this->assertTrue($manager->delete('key3'));
        $this->assertTrue($manager->has('key4'));
        $this->assertTrue($manager->clear());
    }

    /**
     * Tests the delegation of batch operations to the default store.
     */
    public function testDelegatesBatchOperationsToDefaultStore(): void
    {
        // Arrange
        $driverMock = $this->createMock(CacheDriver::class);
        $this->setupDefaultStore($driverMock);
        $manager = $this->createManager();

        $keys = ['key1', 'key2'];
        $values = ['key1' => 'value1', 'key2' => 'value2'];

        $driverMock->expects($this->once())->method('getMultiple')->with($keys, null)->willReturn($values);
        $driverMock->expects($this->once())->method('setMultiple')->with($values, 3600)->willReturn(true);
        $driverMock->expects($this->once())->method('deleteMultiple')->with($keys)->willReturn(true);

        // Act & Assert
        $this->assertSame($values, $manager->getMultiple($keys));
        $this->assertTrue($manager->setMultiple($values, 3600));
        $this->assertTrue($manager->deleteMultiple($keys));
    }

    /**
     * Helper method to set up the default store mock configuration.
     */
    private function setupDefaultStore(CacheDriver $driver): void
    {
        $storeConfig = StoreConfig::fromConfig(['driver' => 'array']);
        $this->configurationMock->method('getDefaultStore')->willReturn('default');
        $this->configurationMock->method('getStore')->with('default')->willReturn($storeConfig);
        $this->driverManagerMock->method('createDriver')->with('array')->willReturn($driver);
    }
}
