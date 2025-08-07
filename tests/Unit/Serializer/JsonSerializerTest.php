<?php

declare(strict_types=1);

namespace KaririCode\Tests\Cache\Unit\Serializer;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\JsonSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the JsonSerializer.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(JsonSerializer::class)]
final class JsonSerializerTest extends TestCase
{
    /**
     * @param mixed $data the data to be serialized
     */
    #[DataProvider('dataProvider')]
    public function testSerializesAndUnserializesDataCorrectly(mixed $data): void
    {
        // Arrange
        $serializer = new JsonSerializer();

        // Act
        $serialized = $serializer->serialize($data);
        $unserialized = $serializer->unserialize($serialized);

        // Assert
        $this->assertEquals($data, $unserialized);
    }

    public function testSerializeThrowsExceptionForUnserializableData(): void
    {
        // Arrange
        $serializer = new JsonSerializer();
        $resource = fopen('php://memory', 'r');

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to serialize data for key 'json'");

        // Act
        try {
            $serializer->serialize($resource);
        } finally {
            fclose($resource);
        }
    }

    public function testUnserializeThrowsExceptionOnMalformedData(): void
    {
        // Arrange
        $serializer = new JsonSerializer();
        $malformedJson = '{"key": "value"'; // Incomplete JSON string

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to deserialize data for key 'json'");

        // Act
        $serializer->unserialize($malformedJson);
    }

    public function testCanSerialize(): void
    {
        // Arrange
        $serializer = new JsonSerializer();
        $resource = fopen('php://memory', 'r');

        // Assert
        $this->assertTrue($serializer->canSerialize(['an' => 'array']));
        $this->assertFalse($serializer->canSerialize($resource));

        fclose($resource);
    }

    public function testCanSerializeReturnsFalseWhenJsonSerializeThrows(): void
    {
        // Arrange
        $serializer = new JsonSerializer();
        $problematicObject = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \Exception('Serialization failed!');
            }
        };

        // Act & Assert
        $this->assertFalse($serializer->canSerialize($problematicObject));
    }

    public function testGetMimeTypeReturnsCorrectString(): void
    {
        // Arrange
        $serializer = new JsonSerializer();

        // Assert
        $this->assertSame('application/json', $serializer->getMimeType());
    }

    public function testGetConfigurationReturnsCorrectScheme(): void
    {
        // Arrange
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        $depth = 256;
        $serializer = new JsonSerializer(flags: $flags, depth: $depth);

        // Act
        $scheme = $serializer->getConfiguration();

        // Assert
        $this->assertSame('json', $scheme->type);
        $this->assertSame($flags, $scheme->flags);
        $this->assertSame($depth, $scheme->depth);
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
            // JSON serialization converts objects to associative arrays
            'object' => [(array) $object],
        ];
    }
}
