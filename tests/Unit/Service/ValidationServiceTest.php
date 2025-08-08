<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Service\ValidationService;
use KaririCode\Cache\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive unit tests for the ValidationService.
 *
 * This test suite validates all public methods and edge cases of the ValidationService,
 * ensuring proper validation of cache keys, values, TTL specifications, and tags
 * according to PSR-6 standards and application-specific requirements.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       ValidationService The class under test
 */
#[CoversClass(ValidationService::class)]
final class ValidationServiceTest extends TestCase
{
    private ValidationService $validator;

    protected function setUp(): void
    {
        $this->validator = new ValidationService();
    }

    // ========================================================================
    // Key Validation Tests - Happy Path
    // ========================================================================

    /**
     * Tests that valid cache keys are accepted without throwing exceptions.
     *
     * @param string $validKey A valid cache key
     */
    #[DataProvider('validKeyProvider')]
    public function testValidateKeyAcceptsValidKeys(string $validKey): void
    {
        // Act & Assert - Should not throw any exception
        $this->validator->validateKey($validKey);

        // If we reach this point, the test passes
        $this->assertTrue(true);
    }

    /**
     * Tests that multiple valid keys are processed correctly.
     */
    public function testValidateKeysAcceptsArrayOfValidKeys(): void
    {
        // Arrange
        $validKeys = ['user:123', 'session_data', 'cache.item.1', 'simple-key'];

        // Act & Assert - Should not throw any exception
        $this->validator->validateKeys($validKeys);

        $this->assertTrue(true);
    }

    // ========================================================================
    // Key Validation Tests - Sad Path
    // ========================================================================

    /**
     * Tests that empty keys are rejected with appropriate exception.
     */
    public function testValidateKeyRejectsEmptyKey(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key cannot be empty');

        // Act
        $this->validator->validateKey('');
    }

    /**
     * Tests that keys exceeding maximum length are rejected.
     */
    public function testValidateKeyRejectsOverlengthKey(): void
    {
        // Arrange
        $longKey = str_repeat('x', 251); // Exceeds MAX_KEY_LENGTH of 250

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key is too long');

        // Act
        $this->validator->validateKey($longKey);
    }

    /**
     * Tests that keys with PSR-6 reserved characters are rejected.
     *
     * @param string $invalidKey A key containing invalid characters
     */
    #[DataProvider('invalidKeyCharacterProvider')]
    public function testValidateKeyRejectsInvalidCharacters(string $invalidKey): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache key format');

        // Act
        $this->validator->validateKey($invalidKey);
    }

    /**
     * Tests that validateKeys rejects non-string values in iterable.
     */
    public function testValidateKeysRejectsNonStringValues(): void
    {
        // Arrange
        $mixedKeys = ['valid_key', 123, 'another_key'];

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All keys in an iterable must be strings');

        // Act
        $this->validator->validateKeys($mixedKeys);
    }

    // ========================================================================
    // Value Validation Tests - Happy Path
    // ========================================================================

    /**
     * Tests that various valid data types can be validated for caching.
     *
     * @param mixed $validValue A value that should be cacheable
     */
    #[DataProvider('validValueProvider')]
    public function testValidateValueAcceptsValidValues(mixed $validValue): void
    {
        // Act & Assert - Should not throw any exception
        $this->validator->validateValue($validValue);

        $this->assertTrue(true);
    }

    /**
     * Tests that validateMultipleValues works with valid key-value pairs.
     */
    public function testValidateMultipleValuesAcceptsValidPairs(): void
    {
        // Arrange
        $validPairs = [
            'string_key' => 'string_value',
            'int_key' => 42,
            'array_key' => ['nested' => 'data'],
        ];

        // Act & Assert - Should not throw any exception
        $this->validator->validateMultipleValues($validPairs);

        $this->assertTrue(true);
    }

    // ========================================================================
    // Value Validation Tests - Sad Path
    // ========================================================================

    /**
     * Tests that resource values are rejected.
     */
    public function testValidateValueRejectsResourceValues(): void
    {
        // Arrange
        $resource = fopen('php://memory', 'r');

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Resources cannot be cached');

        try {
            // Act
            $this->validator->validateValue($resource);
        } finally {
            fclose($resource);
        }
    }

    /**
     * Tests that unserializable values are rejected.
     */
    public function testValidateValueRejectsUnserializableValues(): void
    {
        // Arrange - Closures cannot be serialized
        $closure = fn () => 'test';

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot cache value of type');

        // Act
        $this->validator->validateValue($closure);
    }

    /**
     * Tests that values exceeding size limit are rejected.
     */
    public function testValidateValueRejectsOversizedValues(): void
    {
        // Arrange - Create a value larger than 2MB when serialized
        $largeArray = array_fill(0, 300000, str_repeat('x', 100));

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value size');
        $this->expectExceptionMessage('exceeds the maximum limit');

        // Act
        $this->validator->validateValue($largeArray);
    }

    /**
     * Tests that validateMultipleValues rejects invalid keys.
     */
    public function testValidateMultipleValuesRejectsInvalidKeys(): void
    {
        // Arrange
        $invalidPairs = [
            'valid_key' => 'valid_value',
            'invalid{key}' => 'another_value',
        ];

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache key format');

        // Act
        $this->validator->validateMultipleValues($invalidPairs);
    }

    // ========================================================================
    // TTL Validation Tests - Happy Path
    // ========================================================================

    /**
     * Tests that valid TTL values are accepted.
     *
     * @param \DateInterval|int|null $validTtl A valid TTL specification
     */
    #[DataProvider('validTtlProvider')]
    public function testValidateTtlAcceptsValidValues(\DateInterval|int|null $validTtl): void
    {
        // Act & Assert - Should not throw any exception
        $this->validator->validateTtl($validTtl);

        $this->assertTrue(true);
    }

    // ========================================================================
    // TTL Validation Tests - Sad Path
    // ========================================================================

    /**
     * Tests that negative integer TTL values are rejected.
     */
    public function testValidateTtlRejectsNegativeIntegers(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Integer TTL cannot be negative');

        // Act
        $this->validator->validateTtl(-1);
    }

    /**
     * Tests that excessively large TTL values are rejected.
     */
    public function testValidateTtlRejectsExcessivelyLargeTtl(): void
    {
        // Arrange - TTL larger than 30 days
        $largeTtl = 31 * 24 * 3600; // 31 days in seconds

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL');
        $this->expectExceptionMessage('exceeds maximum allowed');

        // Act
        $this->validator->validateTtl($largeTtl);
    }

    /**
     * Tests that invalid DateInterval TTL values are rejected.
     */
    public function testValidateTtlRejectsInvalidDateInterval(): void
    {
        // Arrange - Create a DateInterval representing negative duration
        $invalidInterval = new \DateInterval('P1D');
        $invalidInterval->invert = 1; // Makes it negative

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DateInterval TTL cannot represent negative duration');

        // Act
        $this->validator->validateTtl($invalidInterval);
    }

    /**
     * Tests that excessively large DateInterval TTL values are rejected.
     */
    public function testValidateTtlRejectsExcessivelyLargeDateInterval(): void
    {
        // Arrange - DateInterval representing more than 30 days
        $largeInterval = new \DateInterval('P31D'); // 31 days

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL');
        $this->expectExceptionMessage('exceeds maximum allowed');

        // Act
        $this->validator->validateTtl($largeInterval);
    }

    // ========================================================================
    // Tag Validation Tests - Happy Path
    // ========================================================================

    /**
     * Tests that valid tags are accepted.
     */
    public function testValidateTagsAcceptsValidStringTags(): void
    {
        // Arrange
        $validTags = ['user', 'session', 'cache-item', 'tag_123'];

        // Act & Assert - Should not throw any exception
        $this->validator->validateTags($validTags);

        $this->assertTrue(true);
    }

    /**
     * Tests that Tag objects are accepted.
     */
    public function testValidateTagsAcceptsTagObjects(): void
    {
        // Arrange
        $tagObjects = [
            Tag::fromString('tag1'),
            Tag::fromString('tag2'),
        ];

        // Act & Assert - Should not throw any exception
        $this->validator->validateTags($tagObjects);

        $this->assertTrue(true);
    }

    /**
     * Tests that mixed string and Tag object collections are accepted.
     */
    public function testValidateTagsAcceptsMixedTagTypes(): void
    {
        // Arrange
        $mixedTags = [
            'string_tag',
            Tag::fromString('object_tag'),
        ];

        // Act & Assert - Should not throw any exception
        $this->validator->validateTags($mixedTags);

        $this->assertTrue(true);
    }

    // ========================================================================
    // Tag Validation Tests - Sad Path
    // ========================================================================

    /**
     * Tests that invalid tag types are rejected.
     */
    public function testValidateTagsRejectsInvalidTypes(): void
    {
        // Arrange
        $invalidTags = ['valid_tag', 123, 'another_tag'];

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tags must be strings or Tag objects');

        // Act
        $this->validator->validateTags($invalidTags);
    }

    /**
     * Tests that empty string tags are rejected.
     */
    public function testValidateTagsRejectsEmptyStringTags(): void
    {
        // Arrange
        $tagsWithEmpty = ['valid_tag', '', 'another_tag'];

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag cannot be empty');

        // Act
        $this->validator->validateTags($tagsWithEmpty);
    }

    /**
     * Tests that tags with invalid characters are rejected.
     *
     * @param string $invalidTag A tag containing invalid characters
     */
    #[DataProvider('invalidTagCharacterProvider')]
    public function testValidateTagsRejectsInvalidCharacters(string $invalidTag): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache tag format');

        // Act
        $this->validator->validateTags([$invalidTag]);
    }

    // ========================================================================
    // Data Providers
    // ========================================================================

    /**
     * Provides valid cache key examples for testing.
     *
     * @return array<string, array<string>>
     */
    public static function validKeyProvider(): array
    {
        return [
            'simple alphanumeric' => ['user123'],
            'with underscores' => ['user_profile_123'],
            'with dots' => ['cache.item.1'],
            'with hyphens' => ['session-data'],
            'mixed valid chars' => ['user.session_123-data'],
            'maximum length' => [str_repeat('a', 250)],
            'single character' => ['a'],
        ];
    }

    /**
     * Provides cache keys with invalid characters.
     *
     * @return array<string, array<string>>
     */
    public static function invalidKeyCharacterProvider(): array
    {
        return [
            'with curly braces' => ['user{123}'],
            'with parentheses' => ['user(123)'],
            'with forward slash' => ['user/123'],
            'with at symbol' => ['user@123'],
            'multiple invalid chars' => ['user{@}'],
        ];
    }

    /**
     * Provides valid values for cache validation testing.
     *
     * @return array<string, array<mixed>>
     */
    public static function validValueProvider(): array
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
            'nested array' => [['level1' => ['level2' => ['level3' => 'value']]]],
        ];
    }

    /**
     * Provides valid TTL values for testing.
     *
     * @return array<string, array<\DateInterval|int|null>>
     */
    public static function validTtlProvider(): array
    {
        return [
            'null TTL' => [null],
            'zero TTL' => [0],
            'positive integer' => [3600],
            'maximum allowed TTL' => [30 * 24 * 3600], // 30 days
            'small DateInterval' => [new \DateInterval('PT1H')], // 1 hour
            'daily DateInterval' => [new \DateInterval('P1D')], // 1 day
            'weekly DateInterval' => [new \DateInterval('P7D')], // 7 days
            'maximum DateInterval' => [new \DateInterval('P30D')], // 30 days
        ];
    }

    /**
     * Provides tags with invalid characters.
     *
     * @return array<string, array<string>>
     */
    public static function invalidTagCharacterProvider(): array
    {
        return [
            'with curly braces' => ['tag{123}'],
            'with parentheses' => ['tag(123)'],
            'with forward slash' => ['tag/123'],
            'with at symbol' => ['tag@123'],
            'with space' => ['invalid tag'],
        ];
    }

    // ========================================================================
    // Edge Case Tests
    // ========================================================================

    /**
     * Tests validation with empty iterables.
     */
    public function testValidateMethodsHandleEmptyIterables(): void
    {
        // Act & Assert - Should not throw any exception
        $this->validator->validateKeys([]);
        $this->validator->validateMultipleValues([]);
        $this->validator->validateTags([]);

        $this->assertTrue(true);
    }

    /**
     * Tests that generators and other iterables work correctly.
     */
    public function testValidateMethodsWorkWithGenerators(): void
    {
        // Arrange
        $keyGenerator = function () {
            yield 'key1';
            yield 'key2';
            yield 'key3';
        };

        // Act & Assert - Should not throw any exception
        $this->validator->validateKeys($keyGenerator());

        $this->assertTrue(true);
    }

    /**
     * Tests boundary values for key length validation.
     */
    public function testKeyLengthBoundaryValidation(): void
    {
        // Arrange
        $exactlyMaxLength = str_repeat('a', 250);
        $justOverMaxLength = str_repeat('a', 251);

        // Act & Assert - Should not throw for exactly max length
        $this->validator->validateKey($exactlyMaxLength);

        // Should throw for just over max length
        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateKey($justOverMaxLength);
    }
}
