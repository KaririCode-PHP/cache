<?php

declare(strict_types=1);

namespace KaririCode\Tests\Cache\Unit\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\SerializationScheme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SerializationScheme Value Object.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(SerializationScheme::class)]
final class SerializationSchemeTest extends TestCase
{
    /**
     * Tests that the object can be created with only the required 'type' property
     * and that all other properties have correct default values.
     */
    public function testCanBeCreatedWithOnlyTypeAndDefaultsAreCorrect(): void
    {
        // Act
        $scheme = new SerializationScheme(type: 'native');

        // Assert
        $this->assertSame('native', $scheme->type);
        $this->assertSame('', $scheme->innerSerializer);
        $this->assertSame(0, $scheme->compressionLevel);
        $this->assertSame(0, $scheme->threshold);
        $this->assertSame(0, $scheme->flags);
        $this->assertSame(0, $scheme->depth);
        $this->assertSame([], $scheme->allowedClasses);
        $this->assertSame([], $scheme->options);
    }

    /**
     * Tests that the object can be created with all properties explicitly provided.
     */
    public function testCanBeCreatedWithAllProperties(): void
    {
        // Arrange
        $allowedClasses = ['stdClass'];
        $options = ['foo' => 'bar'];

        // Act
        $scheme = new SerializationScheme(
            type: 'compressing',
            innerSerializer: 'NativeSerializer',
            compressionLevel: 6,
            threshold: 1024,
            flags: JSON_PRETTY_PRINT,
            depth: 512,
            allowedClasses: $allowedClasses,
            options: $options
        );

        // Assert
        $this->assertSame('compressing', $scheme->type);
        $this->assertSame('NativeSerializer', $scheme->innerSerializer);
        $this->assertSame(6, $scheme->compressionLevel);
        $this->assertSame(1024, $scheme->threshold);
        $this->assertSame(JSON_PRETTY_PRINT, $scheme->flags);
        $this->assertSame(512, $scheme->depth);
        $this->assertSame($allowedClasses, $scheme->allowedClasses);
        $this->assertSame($options, $scheme->options);
    }

    /**
     * Tests that creating the object with an empty or whitespace-only type throws an exception.
     *
     * @param string $invalidType the invalid type string to test
     */
    #[DataProvider('invalidTypeProvider')]
    public function testThrowsExceptionForEmptyOrWhitespaceType(string $invalidType): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serializer type cannot be empty.');

        // Act
        new SerializationScheme(type: $invalidType);
    }

    /**
     * Provides invalid type strings for testing.
     *
     * @return array<string, array<string>>
     */
    public static function invalidTypeProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }
}
