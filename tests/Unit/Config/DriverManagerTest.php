<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\DriverManager as DriverManagerContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\ArrayDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DriverManager class.
 *
 * This suite verifies that the DriverManager correctly registers, manages,
 * and creates cache drivers using the Factory pattern. It ensures that
 * both default and custom factories are handled correctly, and that
 * configuration errors are properly thrown.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(DriverManager::class)]
final class DriverManagerTest extends TestCase
{
    private DriverManager $driverManager;

    protected function setUp(): void
    {
        $this->driverManager = new DriverManager();
    }

    /**
     * Tests that the default driver factories are registered upon instantiation.
     */
    public function testConstructRegistersDefaultFactories(): void
    {
        // Act
        $drivers = $this->driverManager->getRegisteredDrivers();

        // Assert
        $this->assertContains('redis', $drivers);
        $this->assertContains('memcached', $drivers);
        $this->assertContains('apcu', $drivers);
        $this->assertContains('filesystem', $drivers);
        $this->assertContains('array', $drivers);
        $this->assertContains('layered', $drivers);
    }

    /**
     * Tests that a new, valid factory can be added at runtime.
     */
    public function testAddValidFactory(): void
    {
        // Arrange
        $factoryMock = $this->createMock(CacheDriverFactory::class);

        // Act
        $this->driverManager->addFactory('custom', get_class($factoryMock));

        // Assert
        $this->assertTrue($this->driverManager->hasDriver('custom'));
    }

    /**
     * Tests that adding a factory class that doesn't implement the required interface throws an exception.
     */
    public function testAddInvalidFactoryThrowsException(): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Cache factory 'invalid' must implement 'KaririCode\Cache\Contract\CacheDriverFactory'");

        // Act
        $this->driverManager->addFactory('invalid', \stdClass::class);
    }

    /**
     * Tests that a driver can be created successfully using a registered factory.
     */
    public function testCreateDriverSuccessfully(): void
    {
        // Arrange
        $expirationProviderMock = $this->createMock(ExpirationProvider::class);
        $options = ['expiration_provider' => $expirationProviderMock];

        // Act
        $driver = $this->driverManager->createDriver('array', $options);

        // Assert
        $this->assertInstanceOf(CacheDriver::class, $driver);
    }

    /**
     * Tests that attempting to create a non-existent driver throws an exception.
     */
    public function testCreateNonExistentDriverThrowsException(): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Cache driver 'nonexistent' is not configured");

        // Act
        $this->driverManager->createDriver('nonexistent');
    }

    /**
     * Tests that driver-specific options can be set and retrieved.
     */
    public function testSetAndGetDriverOptions(): void
    {
        // Arrange
        $options = ['host' => 'test.host', 'port' => 1234];

        // Act
        $this->driverManager->setDriverOptions('redis', $options);

        // Assert
        $this->assertSame($options, $this->driverManager->getDriverOptions('redis'));
        $this->assertEmpty($this->driverManager->getDriverOptions('memcached'));
    }

    /**
     * Tests that a central serializer can be set and retrieved.
     */
    public function testSetAndGetSerializer(): void
    {
        // Arrange
        $serializerMock = $this->createMock(CacheSerializer::class);

        // Act
        $this->driverManager->setSerializer($serializerMock);

        // Assert
        $this->assertSame($serializerMock, $this->driverManager->getSerializer());
    }

    /**
     * Tests that getSupportedOptions correctly delegates to the specified factory.
     */
    public function testGetSupportedOptionsDelegatesToFactory(): void
    {
        // Arrange: Create a real (but simple) factory class for the test.
        $testFactory = new class implements CacheDriverFactory {
            public function createDriver(array $options, DriverManagerContract $manager): CacheDriver
            {
                return new ArrayDriver($options['expiration_provider']);
            }

            public function getSupportedOptions(): array
            {
                return ['custom_option1', 'custom_option2'];
            }

            public function validateOptions(array $options): bool
            {
                return true;
            }
        };

        $this->driverManager->addFactory('custom', get_class($testFactory));

        // Act
        $supportedOptions = $this->driverManager->getSupportedOptions('custom');

        // Assert
        $this->assertSame(['custom_option1', 'custom_option2'], $supportedOptions);
    }
}
