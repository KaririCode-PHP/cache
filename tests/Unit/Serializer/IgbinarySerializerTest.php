<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Serializer;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\IgbinarySerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the IgbinarySerializer.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(IgbinarySerializer::class)]
final class IgbinarySerializerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }
    }

    /**
     * @param mixed $data the data to be serialized
     */
    #[DataProvider('dataProvider')]
    public function testSerializesAndUnserializesDataCorrectly(mixed $data): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();

        // Act
        $serialized = $serializer->serialize($data);
        $unserialized = $serializer->unserialize($serialized);

        // Assert
        $this->assertEquals($data, $unserialized);
    }

    public function testUnserializeThrowsExceptionOnMalformedData(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();
        $malformedData = "\x00\x01\x02\x03"; // Invalid igbinary data

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to deserialize data for key 'igbinary'");

        // Act
        $serializer->unserialize($malformedData);
    }

    public function testUnserializeHandlesSerializedNullCorrectly(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();
        $serializedNull = \igbinary_serialize(null);

        // Act
        $result = $serializer->unserialize($serializedNull);

        // Assert
        $this->assertNull($result);
    }

    public function testCanSerialize(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();
        $resource = fopen('php://memory', 'r');

        // Assert
        $this->assertTrue($serializer->canSerialize(['an' => 'array']));
        $this->assertFalse($serializer->canSerialize($resource)); // Resources cannot be serialized by igbinary

        fclose($resource);
    }

    public function testSerializeThrowsExceptionForUnserializableData(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();
        $unserializable = fn () => 'hello';

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to serialize data for key 'igbinary'");

        // Act
        $serializer->serialize($unserializable);
    }

    // public function testSerializeThrowsExceptionForResource(): void
    // {
    //     // Arrange
    //     $serializer = new IgbinarySerializer();
    //     $resource = fopen('php://memory', 'r');

    //     // Assert
    //     $this->expectException(CacheException::class);
    //     $this->expectExceptionMessage("Failed to serialize data for key 'igbinary'");

    //     // Act
    //     try {
    //         $serializer->serialize($resource);
    //     } finally {
    //         fclose($resource);
    //     }
    // }

    public function testCanSerializeReturnsFalseForUnserializableData(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();
        $unserializable = fn () => 'hello';

        // Assert
        $this->assertFalse($serializer->canSerialize($unserializable));
    }

    public function testGetMimeTypeReturnsCorrectString(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();

        // Assert
        $this->assertSame('application/x-igbinary', $serializer->getMimeType());
    }

    public function testGetConfigurationReturnsCorrectScheme(): void
    {
        // Arrange
        $serializer = new IgbinarySerializer();

        // Act
        $scheme = $serializer->getConfiguration();

        // Assert
        $this->assertSame('igbinary', $scheme->type);
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
