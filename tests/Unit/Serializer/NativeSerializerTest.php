<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Serializer;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\NativeSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the NativeSerializer.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(NativeSerializer::class)]
final class NativeSerializerTest extends TestCase
{
    /**
     * @param mixed $data the data to be serialized
     */
    #[DataProvider('dataProvider')]
    public function testSerializesAndUnserializesDataCorrectly(mixed $data): void
    {
        // Arrange
        $serializer = new NativeSerializer(allowedClasses: [\stdClass::class]);

        // Act
        $serialized = $serializer->serialize($data);
        $unserialized = $serializer->unserialize($serialized);

        // Assert
        $this->assertEquals($data, $unserialized);
    }

    public function testUnserializeDisallowsClassesByDefault(): void
    {
        // Arrange
        $serializer = new NativeSerializer();
        $object = new \stdClass();
        $object->name = 'test';
        $serializedObject = serialize($object);

        // Act
        $result = $serializer->unserialize($serializedObject);

        // Assert
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $result);
    }

    public function testUnserializeAllowsSpecifiedClasses(): void
    {
        // Arrange
        $serializer = new NativeSerializer(allowedClasses: [\stdClass::class]);
        $object = new \stdClass();
        $object->name = 'test';
        $serializedObject = serialize($object);

        // Act
        $result = $serializer->unserialize($serializedObject);

        // Assert
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals($object, $result);
    }

    public function testUnserializeThrowsExceptionOnMalformedData(): void
    {
        // Arrange
        $serializer = new NativeSerializer();
        $malformedData = 'O:8:"stdClass":1:{s:4:"name";s:4:"test"'; // Incomplete string

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to deserialize data for key 'native'");

        // Act
        $serializer->unserialize($malformedData);
    }

    public function testUnserializeHandlesSerializedFalseCorrectly(): void
    {
        // Arrange
        $serializer = new NativeSerializer();
        $serializedFalse = 'b:0;';

        // Act
        $result = $serializer->unserialize($serializedFalse);

        // Assert
        $this->assertFalse($result);
    }

    public function testCanSerialize(): void
    {
        // Arrange
        $serializer = new NativeSerializer();
        $resource = fopen('php://memory', 'r');

        // Assert
        $this->assertTrue($serializer->canSerialize(['an' => 'array']));
        $this->assertFalse($serializer->canSerialize($resource));

        fclose($resource);
    }

    public function testSerializeThrowsExceptionForUnserializableData(): void
    {
        // Arrange
        $serializer = new NativeSerializer();
        $unserializable = fn () => 'hello';

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to serialize data for key 'Closure'");

        // Act
        $serializer->serialize($unserializable);
    }

    public function testCanSerializeReturnsFalseForUnserializableData(): void
    {
        // Arrange
        $serializer = new NativeSerializer();
        $unserializable = fn () => 'hello';

        // Assert
        $this->assertFalse($serializer->canSerialize($unserializable));
    }

    public function testGetMimeTypeReturnsCorrectString(): void
    {
        // Arrange
        $serializer = new NativeSerializer();

        // Assert
        $this->assertSame('application/vnd.php.serialized', $serializer->getMimeType());
    }

    public function testGetConfigurationReturnsCorrectScheme(): void
    {
        // Arrange
        $serializer = new NativeSerializer(allowedClasses: [\stdClass::class], options: ['foo' => 'bar']);

        // Act
        $scheme = $serializer->getConfiguration();

        // Assert
        $this->assertSame('native', $scheme->type);
        $this->assertSame([\stdClass::class], $scheme->allowedClasses);
        $this->assertSame(['foo' => 'bar'], $scheme->options);
    }

    /**
     * Provides various data types for testing serialization.
     *
     * @return array<string, array<mixed>>
     */
    public static function dataProvider(): array
    {
        $object = new \stdClass();
        $object->id = 1;
        $object->name = 'test';

        return [
            'string' => ['hello world'],
            'integer' => [12345],
            'float' => [3.14159],
            'boolean true' => [true],
            'boolean false' => [false],
            'null value' => [null],
            'simple array' => [['a', 'b', 'c']],
            'associative array' => [['id' => 1, 'name' => 'test']],
            'object' => [$object],
        ];
    }
}
