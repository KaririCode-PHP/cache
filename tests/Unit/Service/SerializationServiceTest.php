<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\ValueObject\SerializationScheme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comprehensive unit tests for the SerializationService.
 *
 * This test suite validates all public methods and edge cases of the SerializationService,
 * ensuring proper serialization/deserialization behavior, serializer registration,
 * error handling, and compliance with the CacheSerializer interface.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       SerializationService The class under test
 */
#[CoversClass(SerializationService::class)]
final class SerializationServiceTest extends TestCase
{
    private SerializationService $service;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new SerializationService($this->logger);
    }

    // ========================================================================
    // Constructor and Initialization Tests
    // ========================================================================

    /**
     * Tests that the service can be created without a logger.
     */
    public function testCanBeCreatedWithoutLogger(): void
    {
        // Act
        $service = new SerializationService();

        // Assert
        $this->assertInstanceOf(SerializationService::class, $service);
        $this->assertSame('native', $service->getDefaultSerializer());
    }

    /**
     * Tests that the service can be created with a custom logger.
     */
    public function testCanBeCreatedWithCustomLogger(): void
    {
        // Arrange
        $logger = $this->createMock(LoggerInterface::class);

        // Act
        $service = new SerializationService($logger);

        // Assert
        $this->assertInstanceOf(SerializationService::class, $service);
    }

    /**
     * Tests that default serializers are automatically registered.
     */
    public function testDefaultSerializersAreRegistered(): void
    {
        // Assert
        $registeredSerializers = $this->service->getRegisteredSerializers();

        $this->assertContains('native', $registeredSerializers);
        $this->assertContains('json', $registeredSerializers);

        // Igbinary is only available if the extension is loaded
        if (extension_loaded('igbinary')) {
            $this->assertContains('igbinary', $registeredSerializers);
            $this->assertTrue($this->service->hasSerializer('igbinary'));

            // Test basic igbinary functionality if available
            $testData = ['test' => 'data'];
            $serialized = $this->service->serialize($testData, 'igbinary');
            $deserialized = $this->service->unserialize($serialized, 'igbinary');
            $this->assertEquals($testData, $deserialized);
        } else {
            $this->assertNotContains('igbinary', $registeredSerializers);
            $this->assertFalse($this->service->hasSerializer('igbinary'));
        }
    }

    // ========================================================================
    // Serializer Registration Tests - Happy Path
    // ========================================================================

    /**
     * Tests that custom serializers can be registered successfully.
     */
    public function testRegisterSerializerSucceeds(): void
    {
        // Arrange
        $customSerializer = $this->createMock(CacheSerializer::class);

        // Act
        $this->service->registerSerializer('custom', $customSerializer);

        // Assert
        $this->assertTrue($this->service->hasSerializer('custom'));
        $this->assertContains('custom', $this->service->getRegisteredSerializers());
    }

    /**
     * Tests that the logger is called when registering a serializer.
     */
    public function testRegisterSerializerLogsSuccess(): void
    {
        // Arrange
        $customSerializer = $this->createMock(CacheSerializer::class);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Serializer registered successfully', $this->isType('array'));

        // Act
        $this->service->registerSerializer('custom', $customSerializer);
    }

    /**
     * Tests that existing serializers can be overridden.
     */
    public function testRegisterSerializerCanOverrideExisting(): void
    {
        // Arrange
        $newNativeSerializer = $this->createMock(CacheSerializer::class);
        $originalCount = count($this->service->getRegisteredSerializers());

        // Act
        $this->service->registerSerializer('native', $newNativeSerializer);

        // Assert
        $this->assertCount($originalCount, $this->service->getRegisteredSerializers());
        $this->assertTrue($this->service->hasSerializer('native'));
    }

    // ========================================================================
    // Serializer Registration Tests - Sad Path
    // ========================================================================

    /**
     * Tests that registering with empty name throws exception.
     */
    public function testRegisterSerializerRejectsEmptyName(): void
    {
        // Arrange
        $serializer = $this->createMock(CacheSerializer::class);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serializer name cannot be empty');

        // Act
        $this->service->registerSerializer('', $serializer);
    }

    /**
     * Tests that registering with invalid name format throws exception.
     *
     * @param string $invalidName Invalid serializer name
     */
    #[DataProvider('invalidSerializerNameProvider')]
    public function testRegisterSerializerRejectsInvalidNames(string $invalidName): void
    {
        // Arrange
        $serializer = $this->createMock(CacheSerializer::class);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid serializer name');

        // Act
        $this->service->registerSerializer($invalidName, $serializer);
    }

    // ========================================================================
    // Default Serializer Management Tests - Happy Path
    // ========================================================================

    /**
     * Tests that default serializer can be changed to a registered serializer.
     */
    public function testSetDefaultSerializerSucceeds(): void
    {
        // Act
        $this->service->setDefaultSerializer('json');

        // Assert
        $this->assertSame('json', $this->service->getDefaultSerializer());
    }

    /**
     * Tests that changing default serializer is logged.
     */
    public function testSetDefaultSerializerLogsChange(): void
    {
        // Arrange
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Default serializer changed', $this->isType('array'));

        // Act
        $this->service->setDefaultSerializer('json');
    }

    /**
     * Tests getting the initial default serializer.
     */
    public function testGetDefaultSerializerReturnsInitialDefault(): void
    {
        // Assert
        $this->assertSame('native', $this->service->getDefaultSerializer());
    }

    // ========================================================================
    // Default Serializer Management Tests - Sad Path
    // ========================================================================

    /**
     * Tests that setting non-existent serializer as default throws exception.
     */
    public function testSetDefaultSerializerRejectsUnregisteredSerializer(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serializer "nonexistent" is not registered');

        // Act
        $this->service->setDefaultSerializer('nonexistent');
    }

    // ========================================================================
    // Serialization Tests - Happy Path
    // ========================================================================

    /**
     * Tests successful serialization with default serializer.
     *
     * @param mixed $value Value to serialize
     */
    #[DataProvider('serializableValueProvider')]
    public function testSerializeWithDefaultSerializerSucceeds(mixed $value): void
    {
        // Act
        $result = $this->service->serialize($value);

        // Assert
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Tests successful serialization with specific serializer.
     */
    public function testSerializeWithSpecificSerializerSucceeds(): void
    {
        // Arrange
        $data = ['key' => 'value'];

        // Act
        $result = $this->service->serialize($data, 'json');

        // Assert
        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Tests serialization with roundtrip consistency.
     */
    public function testSerializeDeserializeRoundtripConsistency(): void
    {
        // Arrange
        $originalData = [
            'string' => 'test',
            'integer' => 42,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value'],
        ];

        // Act
        $serialized = $this->service->serialize($originalData);
        $deserialized = $this->service->unserialize($serialized);

        // Assert
        $this->assertEquals($originalData, $deserialized);
    }

    /**
     * Tests that successful serialization is logged.
     */
    public function testSerializeLogsSuccess(): void
    {
        // Arrange
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Value serialized successfully', $this->isType('array'));

        // Act
        $this->service->serialize('test value');
    }

    // ========================================================================
    // Serialization Tests - Sad Path
    // ========================================================================

    /**
     * Tests serialization with non-existent serializer throws exception.
     */
    public function testSerializeWithUnregisteredSerializerThrowsException(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serializer "nonexistent" is not registered');

        // Act
        $this->service->serialize('test', 'nonexistent');
    }

    /**
     * Tests that serialization failure is properly handled and logged.
     */
    public function testSerializeHandlesSerializationFailure(): void
    {
        // Arrange
        $failingSerializer = $this->createMock(CacheSerializer::class);
        $failingSerializer->method('serialize')
            ->willThrowException(new \RuntimeException('Serialization failed'));

        $this->service->registerSerializer('failing', $failingSerializer);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Serialization failed', $this->isType('array'));

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to serialize');

        // Act
        $this->service->serialize('test', 'failing');
    }

    // ========================================================================
    // Deserialization Tests - Happy Path
    // ========================================================================

    /**
     * Tests successful deserialization with default serializer.
     *
     * @param mixed $value Value to serialize and then deserialize
     */
    #[DataProvider('serializableValueProvider')]
    public function testDeserializeWithDefaultSerializerSucceeds(mixed $value): void
    {
        // Arrange
        $serialized = $this->service->serialize($value);

        // Act
        $result = $this->service->unserialize($serialized);

        // Assert
        // For objects, the native serializer without allowed classes will create __PHP_Incomplete_Class
        if (is_object($value)) {
            $this->assertIsObject($result);
            $this->assertEquals($value->property ?? null, $result->property ?? null);
        } else {
            $this->assertEquals($value, $result);
        }
    }

    /**
     * Tests successful deserialization with specific serializer.
     */
    public function testDeserializeWithSpecificSerializerSucceeds(): void
    {
        // Arrange
        $data = ['key' => 'value'];
        $serialized = $this->service->serialize($data, 'json');

        // Act
        $result = $this->service->unserialize($serialized, 'json');

        // Assert
        $this->assertEquals($data, $result);
    }

    /**
     * Tests deserialization of special values.
     */
    public function testDeserializeHandlesSpecialValues(): void
    {
        // Arrange & Act & Assert
        $serializedFalse = $this->service->serialize(false);
        $this->assertFalse($this->service->unserialize($serializedFalse));

        $serializedNull = $this->service->serialize(null);
        $this->assertNull($this->service->unserialize($serializedNull));

        $serializedZero = $this->service->serialize(0);
        $this->assertSame(0, $this->service->unserialize($serializedZero));
    }

    /**
     * Tests deserialization works correctly with allowed classes.
     */
    public function testDeserializeWorksCorrectlyWithAllowedClasses(): void
    {
        // Arrange
        $nativeSerializerWithClasses = new NativeSerializer(allowedClasses: [\stdClass::class]);
        $this->service->registerSerializer('native_with_classes', $nativeSerializerWithClasses);

        $object = new \stdClass();
        $object->property = 'test_value';

        // Act
        $serialized = $this->service->serialize($object, 'native_with_classes');
        $deserialized = $this->service->unserialize($serialized, 'native_with_classes');

        // Assert
        $this->assertInstanceOf(\stdClass::class, $deserialized);
        $this->assertEquals($object, $deserialized);
    }

    /**
     * Tests that successful deserialization is logged.
     */
    public function testDeserializeLogsSuccess(): void
    {
        // Arrange
        $serialized = $this->service->serialize('test');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Data deserialized successfully', $this->isType('array'));

        // Act
        $this->service->unserialize($serialized);
    }

    // ========================================================================
    // Deserialization Tests - Sad Path
    // ========================================================================

    /**
     * Tests deserialization with non-existent serializer throws exception.
     */
    public function testDeserializeWithUnregisteredSerializerThrowsException(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serializer "nonexistent" is not registered');

        // Act
        $this->service->unserialize('data', 'nonexistent');
    }

    /**
     * Tests that deserialization failure is properly handled and logged.
     */
    public function testDeserializeHandlesDeserializationFailure(): void
    {
        // Arrange
        $failingSerializer = $this->createMock(CacheSerializer::class);
        $failingSerializer->method('unserialize')
            ->willThrowException(new \RuntimeException('Deserialization failed'));

        $this->service->registerSerializer('failing', $failingSerializer);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Deserialization failed', $this->isType('array'));

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to deserialize');

        // Act
        $this->service->unserialize('invalid data', 'failing');
    }

    /**
     * Tests deserialization of malformed data throws exception.
     */
    public function testDeserializeMalformedDataThrowsException(): void
    {
        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to deserialize');

        // Act
        $this->service->unserialize('malformed serialized data', 'native');
    }

    // ========================================================================
    // CacheSerializer Interface Implementation Tests
    // ========================================================================

    /**
     * Tests canSerialize method with various data types.
     *
     * @param mixed $value Value to test
     * @param bool $expected Expected result
     */
    #[DataProvider('canSerializeProvider')]
    public function testCanSerializeReturnsCorrectResult(mixed $value, bool $expected): void
    {
        // Act
        $result = $this->service->canSerialize($value);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * Tests canSerialize specifically with resource (separate test due to resource cleanup).
     */
    public function testCanSerializeReturnsFalseForResource(): void
    {
        // Arrange
        $resource = fopen('php://memory', 'r');

        try {
            // Act
            $result = $this->service->canSerialize($resource);

            // Assert
            $this->assertFalse($result);
        } finally {
            fclose($resource);
        }
    }

    /**
     * Tests canSerialize with specific serializer.
     */
    public function testCanSerializeWithSpecificSerializer(): void
    {
        // Arrange
        $data = ['key' => 'value'];

        // Act & Assert
        $this->assertTrue($this->service->canSerialize($data, 'json'));
        $this->assertTrue($this->service->canSerialize($data, 'native'));
    }

    /**
     * Tests canSerialize handles serializer errors gracefully.
     */
    public function testCanSerializeHandlesSerializerErrors(): void
    {
        // Arrange
        $failingSerializer = $this->createMock(CacheSerializer::class);
        $failingSerializer->method('canSerialize')
            ->willThrowException(new \RuntimeException('Serializer error'));

        $this->service->registerSerializer('failing', $failingSerializer);

        $this->logger->expects($this->once())
            ->method('warning');

        // Act
        $result = $this->service->canSerialize('test', 'failing');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests getMimeType returns correct MIME type for default serializer.
     */
    public function testGetMimeTypeReturnsCorrectType(): void
    {
        // Act
        $mimeType = $this->service->getMimeType();

        // Assert
        $this->assertSame('application/vnd.php.serialized', $mimeType);
    }

    /**
     * Tests getMimeType changes when default serializer changes.
     */
    public function testGetMimeTypeChangesWithDefaultSerializer(): void
    {
        // Act
        $this->service->setDefaultSerializer('json');
        $mimeType = $this->service->getMimeType();

        // Assert
        $this->assertSame('application/json', $mimeType);
    }

    /**
     * Tests getConfiguration returns valid configuration for default serializer.
     */
    public function testGetConfigurationReturnsValidConfiguration(): void
    {
        // Act
        $config = $this->service->getConfiguration();

        // Assert
        $this->assertInstanceOf(SerializationScheme::class, $config);
        $this->assertSame('native', $config->type);
    }

    /**
     * Tests getConfiguration changes when default serializer changes.
     */
    public function testGetConfigurationChangesWithDefaultSerializer(): void
    {
        // Act
        $this->service->setDefaultSerializer('json');
        $config = $this->service->getConfiguration();

        // Assert
        $this->assertInstanceOf(SerializationScheme::class, $config);
        $this->assertSame('json', $config->type);
    }

    // ========================================================================
    // Utility Methods Tests
    // ========================================================================

    /**
     * Tests hasSerializer method accurately reports registration status.
     */
    public function testHasSerializerReportsRegistrationStatusAccurately(): void
    {
        // Assert - Default serializers
        $this->assertTrue($this->service->hasSerializer('native'));
        $this->assertTrue($this->service->hasSerializer('json'));
        $this->assertFalse($this->service->hasSerializer('nonexistent'));

        // Arrange & Act - Custom serializer
        $customSerializer = $this->createMock(CacheSerializer::class);
        $this->service->registerSerializer('custom', $customSerializer);

        // Assert
        $this->assertTrue($this->service->hasSerializer('custom'));
    }

    /**
     * Tests getRegisteredSerializers returns complete list.
     */
    public function testGetRegisteredSerializersReturnsCompleteList(): void
    {
        // Arrange
        $customSerializer = $this->createMock(CacheSerializer::class);
        $this->service->registerSerializer('custom', $customSerializer);

        // Act
        $registeredSerializers = $this->service->getRegisteredSerializers();

        // Assert
        $this->assertContains('native', $registeredSerializers);
        $this->assertContains('json', $registeredSerializers);
        $this->assertContains('custom', $registeredSerializers);
    }

    /**
     * Tests that clearSerializers removes all registered serializers.
     */
    public function testClearSerializersRemovesAllSerializers(): void
    {
        // Arrange: Verify initial state with default serializers
        $this->assertNotEmpty($this->service->getRegisteredSerializers());
        $this->assertTrue($this->service->hasSerializer('native'));

        // Arrange: Add a custom serializer to ensure it's also removed
        $customSerializer = $this->createMock(CacheSerializer::class);
        $this->service->registerSerializer('custom', $customSerializer);
        $this->assertTrue($this->service->hasSerializer('custom'));

        // Act: Call the method under test
        $this->service->clearSerializers();

        // Assert: The list of registered serializers should now be empty
        $this->assertEmpty($this->service->getRegisteredSerializers());
        $this->assertFalse($this->service->hasSerializer('native'));
        $this->assertFalse($this->service->hasSerializer('custom'));

        // Assert: Attempting to get a serializer should now fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serializer "native" is not registered');
        $this->service->getSerializer(); // This uses the default name 'native'
    }

    // ========================================================================
    // JSON Serializer Specific Tests
    // ========================================================================

    /**
     * Tests JSON serialization with various data types.
     *
     * @param mixed $value Value to serialize with JSON
     * @param mixed $expectedAfterRoundtrip Expected value after JSON roundtrip
     */
    #[DataProvider('jsonSerializableValueProvider')]
    public function testJsonSerializationRoundtrip(mixed $value, mixed $expectedAfterRoundtrip): void
    {
        // Act
        $serialized = $this->service->serialize($value, 'json');
        $deserialized = $this->service->unserialize($serialized, 'json');

        // Assert
        $this->assertJson($serialized);
        $this->assertEquals($expectedAfterRoundtrip, $deserialized);
    }

    /**
     * Tests that JSON serializer produces valid JSON strings.
     */
    public function testJsonSerializerProducesValidJson(): void
    {
        // Arrange
        $data = [
            'string' => 'hello',
            'number' => 42,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'object' => ['key' => 'value'],
        ];

        // Act
        $result = $this->service->serialize($data, 'json');

        // Assert
        $this->assertJson($result);
        $decoded = json_decode($result, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Tests JSON serializer with objects (converts to arrays).
     */
    public function testJsonSerializerConvertsObjectsToArrays(): void
    {
        // Arrange
        $object = new \stdClass();
        $object->property1 = 'value1';
        $object->property2 = 42;

        $expectedArray = [
            'property1' => 'value1',
            'property2' => 42,
        ];

        // Act
        $serialized = $this->service->serialize($object, 'json');
        $deserialized = $this->service->unserialize($serialized, 'json');

        // Assert
        $this->assertJson($serialized);
        $this->assertEquals($expectedArray, $deserialized);
        $this->assertIsArray($deserialized);
    }

    /**
     * Tests JSON serializer with nested structures.
     */
    public function testJsonSerializerHandlesNestedStructures(): void
    {
        // Arrange
        $complexData = [
            'users' => [
                ['id' => 1, 'name' => 'John', 'active' => true],
                ['id' => 2, 'name' => 'Jane', 'active' => false],
            ],
            'metadata' => [
                'total' => 2,
                'page' => 1,
                'filters' => ['active' => null],
            ],
        ];

        // Act
        $serialized = $this->service->serialize($complexData, 'json');
        $deserialized = $this->service->unserialize($serialized, 'json');

        // Assert
        $this->assertJson($serialized);
        $this->assertEquals($complexData, $deserialized);
    }

    /**
     * Tests JSON serializer with UTF-8 characters.
     */
    public function testJsonSerializerHandlesUtf8Characters(): void
    {
        // Arrange
        $utf8Data = [
            'english' => 'Hello World',
            'portuguese' => 'Olá Mundo',
            'japanese' => 'こんにちは世界',
            'emoji' => '🌍🚀💫',
            'special' => 'áéíóú àèìòù âêîôû ãõ ç',
        ];

        // Act
        $serialized = $this->service->serialize($utf8Data, 'json');
        $deserialized = $this->service->unserialize($serialized, 'json');

        // Assert
        $this->assertJson($serialized);
        $this->assertEquals($utf8Data, $deserialized);

        // Verify UTF-8 characters are properly handled (not escaped)
        $this->assertStringContainsString('Olá Mundo', $serialized);
        $this->assertStringContainsString('🌍🚀💫', $serialized);
    }

    /**
     * Tests JSON serializer MIME type.
     */
    public function testJsonSerializerMimeType(): void
    {
        // Arrange
        $this->service->setDefaultSerializer('json');

        // Act
        $mimeType = $this->service->getMimeType();

        // Assert
        $this->assertSame('application/json', $mimeType);
    }

    /**
     * Tests JSON serializer configuration.
     */
    public function testJsonSerializerConfiguration(): void
    {
        // Arrange
        $this->service->setDefaultSerializer('json');

        // Act
        $config = $this->service->getConfiguration();

        // Assert
        $this->assertInstanceOf(SerializationScheme::class, $config);
        $this->assertSame('json', $config->type);
        $this->assertIsInt($config->flags);
        $this->assertIsInt($config->depth);
    }

    /**
     * Tests canSerialize with JSON serializer for various data types.
     *
     * @param mixed $value Value to test
     * @param bool $expected Expected canSerialize result
     */
    #[DataProvider('jsonCanSerializeProvider')]
    public function testJsonCanSerializeVariousTypes(mixed $value, bool $expected): void
    {
        // Act
        $result = $this->service->canSerialize($value, 'json');

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * Tests JSON serializer with data that causes JSON errors.
     */
    public function testJsonSerializerWithInvalidData(): void
    {
        // Arrange - Create data that will cause JSON encoding issues
        $resource = fopen('php://memory', 'r');

        try {
            // Assert
            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Failed to serialize');

            // Act
            $this->service->serialize($resource, 'json');
        } finally {
            fclose($resource);
        }
    }

    /**
     * Tests JSON deserializer with malformed JSON.
     */
    public function testJsonDeserializerWithMalformedJson(): void
    {
        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to deserialize');

        // Act
        $this->service->unserialize('{"invalid": json}', 'json');
    }

    /**
     * Tests JSON serialization of large nested structures.
     */
    public function testJsonSerializationOfLargeNestedStructures(): void
    {
        // Arrange
        $largeData = [];
        for ($i = 0; $i < 100; ++$i) {
            $largeData["item_$i"] = [
                'id' => $i,
                'data' => str_repeat('x', 50),
                'nested' => ['level' => $i, 'values' => range(1, $i + 1)],
            ];
        }

        // Act
        $serialized = $this->service->serialize($largeData, 'json');
        $deserialized = $this->service->unserialize($serialized, 'json');

        // Assert
        $this->assertJson($serialized);
        $this->assertEquals($largeData, $deserialized);
        $this->assertGreaterThan(10000, strlen($serialized)); // Should be substantial size
    }

    /**
     * Tests that the service works correctly with empty serializers list (hypothetically).
     */
    public function testServiceWorksWithMinimalConfiguration(): void
    {
        // Arrange - Create service and immediately check its basic functionality
        $service = new SerializationService(new NullLogger());

        // Assert
        $this->assertIsArray($service->getRegisteredSerializers());
        $this->assertGreaterThanOrEqual(2, count($service->getRegisteredSerializers()));
    }

    /**
     * Tests behavior with different logger configurations.
     */
    public function testWorksWithDifferentLoggerConfigurations(): void
    {
        // Arrange & Act
        $serviceWithNullLogger = new SerializationService(new NullLogger());
        $serviceWithMockLogger = new SerializationService($this->createMock(LoggerInterface::class));
        $serviceWithoutLogger = new SerializationService();

        // Assert
        $this->assertInstanceOf(SerializationService::class, $serviceWithNullLogger);
        $this->assertInstanceOf(SerializationService::class, $serviceWithMockLogger);
        $this->assertInstanceOf(SerializationService::class, $serviceWithoutLogger);
    }

    /**
     * Tests multiple serializer registrations and operations.
     */
    public function testMultipleSerializerOperations(): void
    {
        // Arrange
        $serializer1 = $this->createMock(CacheSerializer::class);
        $serializer2 = $this->createMock(CacheSerializer::class);

        $serializer1->method('serialize')->willReturn('serialized1');
        $serializer1->method('unserialize')->willReturn('value1');

        $serializer2->method('serialize')->willReturn('serialized2');
        $serializer2->method('unserialize')->willReturn('value2');

        // Act
        $this->service->registerSerializer('test1', $serializer1);
        $this->service->registerSerializer('test2', $serializer2);

        // Assert
        $this->assertSame('serialized1', $this->service->serialize('test', 'test1'));
        $this->assertSame('serialized2', $this->service->serialize('test', 'test2'));
        $this->assertSame('value1', $this->service->unserialize('data', 'test1'));
        $this->assertSame('value2', $this->service->unserialize('data', 'test2'));
    }

    // ========================================================================
    // Data Providers
    // ========================================================================

    /**
     * Provides invalid serializer names for testing.
     *
     * @return array<string, array<string>>
     */
    public static function invalidSerializerNameProvider(): array
    {
        return [
            'starts with number' => ['123invalid'],
            'contains space' => ['invalid name'],
            'contains special chars' => ['invalid-name!'],
            'contains dots' => ['invalid.name'],
            'starts with underscore' => ['_invalid'],
            'only numbers' => ['123'],
            'contains at symbol' => ['invalid@name'],
        ];
    }

    /**
     * Provides various serializable values for testing.
     *
     * @return array<string, array<mixed>>
     */
    public static function serializableValueProvider(): array
    {
        $object = new \stdClass();
        $object->property = 'value';

        return [
            'string' => ['hello world'],
            'integer' => [42],
            'float' => [3.14159],
            'boolean true' => [true],
            'boolean false' => [false],
            'null' => [null],
            'empty string' => [''],
            'zero' => [0],
            'simple array' => [['a', 'b', 'c']],
            'associative array' => [['key' => 'value']],
            'nested array' => [['level1' => ['level2' => 'value']]],
            'object' => [$object],
        ];
    }

    /**
     * Provides test cases for canSerialize method.
     *
     * @return array<string, array<mixed>>
     */
    public static function canSerializeProvider(): array
    {
        $closure = fn () => 'test';

        return [
            'string can serialize' => ['test', true],
            'array can serialize' => [['key' => 'value'], true],
            'object can serialize' => [(object) ['prop' => 'value'], true],
            'closure cannot serialize' => [$closure, false],
        ];
    }

    /**
     * Provides values for JSON serialization testing with expected results after roundtrip.
     *
     * @return array<string, array<mixed>>
     */
    public static function jsonSerializableValueProvider(): array
    {
        $object = new \stdClass();
        $object->prop1 = 'value1';
        $object->prop2 = 42;

        return [
            'string' => ['hello', 'hello'],
            'integer' => [42, 42],
            'float' => [3.14, 3.14],
            'boolean true' => [true, true],
            'boolean false' => [false, false],
            'null' => [null, null],
            'empty string' => ['', ''],
            'zero' => [0, 0],
            'simple array' => [['a', 'b', 'c'], ['a', 'b', 'c']],
            'associative array' => [['key' => 'value'], ['key' => 'value']],
            'nested array' => [['level1' => ['level2' => 'value']], ['level1' => ['level2' => 'value']]],
            // Objects are converted to associative arrays in JSON
            'object becomes array' => [$object, ['prop1' => 'value1', 'prop2' => 42]],
        ];
    }

    /**
     * Provides test cases for JSON canSerialize method.
     *
     * @return array<string, array<mixed>>
     */
    public static function jsonCanSerializeProvider(): array
    {
        return [
            'string can serialize' => ['test', true],
            'array can serialize' => [['key' => 'value'], true],
            'object can serialize' => [(object) ['prop' => 'value'], true],
            'integer can serialize' => [42, true],
            'float can serialize' => [3.14, true],
            'boolean can serialize' => [true, true],
            'null can serialize' => [null, true],
            'closure cannot serialize' => [fn () => 'test', false],
        ];
    }
}
