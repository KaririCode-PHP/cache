<?php

declare(strict_types=1);

namespace KaririCode\Tests\Cache\Unit\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\CompressingSerializer;
use KaririCode\Cache\Serializer\NativeSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CompressingSerializer decorator.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(CompressingSerializer::class)]
final class CompressingSerializerTest extends TestCase
{
    private CacheSerializer $innerSerializer;
    private CompressingSerializer $compressingSerializer;

    protected function setUp(): void
    {
        // Allow stdClass to be unserialized to fix the __PHP_Incomplete_Class error.
        $this->innerSerializer = new NativeSerializer(allowedClasses: [\stdClass::class]);
        // Use a low threshold for easier testing
        $this->compressingSerializer = new CompressingSerializer($this->innerSerializer, 6, 50);
    }

    /**
     * @param mixed $data the data to be serialized
     */
    #[DataProvider('dataProvider')]
    public function testSerializesAndUnserializesDataCorrectly(mixed $data): void
    {
        // Act
        $serialized = $this->compressingSerializer->serialize($data);
        $unserialized = $this->compressingSerializer->unserialize($serialized);

        // Assert
        $this->assertEquals($data, $unserialized);
    }

    public function testDataBelowThresholdIsNotCompressed(): void
    {
        // Arrange
        $smallData = 'this is a short string'; // Length is less than the threshold of 50

        // Act
        $serialized = $this->compressingSerializer->serialize($smallData);

        // Assert
        $this->assertStringStartsWith('raw:', $serialized);
    }

    public function testDataAboveThresholdIsCompressed(): void
    {
        // Arrange
        $largeData = str_repeat('this is a long string that should be compressed. ', 10);

        // Act
        $serialized = $this->compressingSerializer->serialize($largeData);

        // Assert
        $this->assertStringStartsWith('gz:', $serialized);
    }

    public function testIneffectiveCompressionStoresRawData(): void
    {
        // Arrange
        // This data is already compressed, so gzcompress will likely make it larger.
        $alreadyCompressed = gzcompress('some random data');

        // Act
        $serialized = $this->compressingSerializer->serialize($alreadyCompressed);

        // Assert
        $this->assertStringStartsWith('raw:', $serialized);
    }

    public function testUnserializesLegacyDataWithoutPrefix(): void
    {
        // Arrange
        $legacyData = ['user_id' => 123, 'name' => 'John Doe'];
        $serializedLegacy = $this->innerSerializer->serialize($legacyData);

        // Act
        $unserialized = $this->compressingSerializer->unserialize($serializedLegacy);

        // Assert
        $this->assertEquals($legacyData, $unserialized);
    }

    public function testUnserializeThrowsExceptionOnCorruptCompressedData(): void
    {
        // Arrange
        $corruptData = 'gz:thisisnotvalidgzippeddata';

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Failed to deserialize data for key 'compressing'");

        // Act
        $this->compressingSerializer->unserialize($corruptData);
    }

    public function testGetConfigurationReturnsCorrectScheme(): void
    {
        // Act
        $scheme = $this->compressingSerializer->getConfiguration();

        // Assert
        $this->assertSame('compressing', $scheme->type);
        $this->assertSame(NativeSerializer::class, $scheme->innerSerializer);
        $this->assertSame(6, $scheme->compressionLevel);
        $this->assertSame(50, $scheme->threshold);
    }

    public function testCanSerializeHandlesDifferentDataTypes(): void
    {
        // Assert
        $this->assertTrue($this->compressingSerializer->canSerialize(['an' => 'array']));

        $resource = fopen('php://memory', 'r');
        $this->assertFalse($this->compressingSerializer->canSerialize($resource));
        fclose($resource);
    }

    public function testGetMimeTypeReturnsCorrectString(): void
    {
        // Assert
        $this->assertSame('application/x-php-compressed', $this->compressingSerializer->getMimeType());
    }

    /**
     * Provides various data types for testing serialization.
     *
     * @return array<string, array<mixed>>
     */
    public static function dataProvider(): array
    {
        return [
            'short string' => ['hello world'],
            'long string' => [str_repeat('abcdefghijklmnopqrstuvwxyz', 10)],
            'integer' => [12345],
            'float' => [3.14159],
            'boolean true' => [true],
            'boolean false' => [false],
            'null value' => [null],
            'simple array' => [['a', 'b', 'c']],
            'associative array' => [['id' => 1, 'name' => 'test']],
            'object' => [(object) ['id' => 1, 'name' => 'test']],
        ];
    }
}
