<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Tag Value Object.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(Tag::class)]
final class TagTest extends TestCase
{
    // ========================================================================
    // Happy Path Tests (Successful Scenarios)
    // ========================================================================

    public function testCanBeCreatedFromValidString(): void
    {
        $tag = Tag::fromString('my-valid-tag');

        $this->assertInstanceOf(Tag::class, $tag);
        $this->assertSame('my-valid-tag', (string) $tag);
    }

    public function testTagIsNormalizedToLowerCaseAndTrimmed(): void
    {
        $tag = Tag::fromString('  My-Tag_1.2  ');

        $this->assertSame('my-tag_1.2', (string) $tag);
    }

    public function testEqualsReturnsTrueForEquivalentTags(): void
    {
        $tag1 = Tag::fromString('MyTag');
        $tag2 = Tag::fromString('mytag');
        $tag3 = Tag::fromString('  mytag  ');

        $this->assertTrue($tag1->equals($tag2));
        $this->assertTrue($tag1->equals($tag3));
    }

    public function testEqualsReturnsFalseForDifferentTags(): void
    {
        $tag1 = Tag::fromString('tag1');
        $tag2 = Tag::fromString('tag2');

        $this->assertFalse($tag1->equals($tag2));
    }

    // ========================================================================
    // Sad Path Tests (Failure Scenarios)
    // ========================================================================

    public function testCannotBeCreatedFromEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag cannot be empty.');

        Tag::fromString('');
    }

    public function testCannotBeCreatedFromOverlyLongString(): void
    {
        $longString = str_repeat('a', 101);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag cannot exceed 100 characters.');

        Tag::fromString($longString);
    }

    /**
     * @param string $invalidValue the invalid string to test
     */
    #[DataProvider('invalidCharacterProvider')]
    public function testCannotBeCreatedWithInvalidCharacters(string $invalidValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        // CORREÇÃO: A mensagem esperada foi ajustada para ser mais precisa.
        $this->expectExceptionMessage('Invalid cache tag format');

        Tag::fromString($invalidValue);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function invalidCharacterProvider(): array
    {
        return [
            'with space' => ['invalid tag'],
            'with exclamation mark' => ['invalid!'],
            'with slash' => ['user/profile'],
            'with at symbol' => ['user@1'],
            'with curly braces' => ['{tag}'],
            'with colon' => ['tag:id'],
        ];
    }
}
