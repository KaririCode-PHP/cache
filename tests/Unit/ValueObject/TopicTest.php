<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Topic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Topic Value Object.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(Topic::class)]
final class TopicTest extends TestCase
{
    // ========================================================================
    // Happy Path Tests (Successful Scenarios)
    // ========================================================================

    /**
     * Tests that a Topic can be successfully created from a valid string.
     */
    public function testCanBeCreatedFromValidString(): void
    {
        $topic = Topic::fromString('users.profiles:123');

        $this->assertInstanceOf(Topic::class, $topic);
        $this->assertSame('users.profiles:123', (string) $topic);
    }

    /**
     * Tests that the input string is correctly normalized (trimmed).
     */
    public function testTopicIsNormalizedByTrimming(): void
    {
        $topic = Topic::fromString('  some-topic  ');

        $this->assertSame('some-topic', (string) $topic);
    }

    /**
     * Tests that the equals() method returns true for equivalent topics.
     */
    public function testEqualsReturnsTrueForEquivalentTopics(): void
    {
        $topic1 = Topic::fromString('  topic-1  ');
        $topic2 = Topic::fromString('topic-1');

        $this->assertTrue($topic1->equals($topic2));
    }

    /**
     * Tests that the equals() method returns false for different topics.
     */
    public function testEqualsReturnsFalseForDifferentTopics(): void
    {
        $topic1 = Topic::fromString('topic-1');
        $topic2 = Topic::fromString('topic-2');

        $this->assertFalse($topic1->equals($topic2));
    }

    // ========================================================================
    // Sad Path Tests (Failure Scenarios)
    // ========================================================================

    /**
     * Tests that creating a Topic from an empty string throws an exception.
     */
    public function testCannotBeCreatedFromEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Topic cannot be empty.');

        Topic::fromString('  '); // Also tests after trimming
    }

    /**
     * Tests that creating a Topic from an overly long string throws an exception.
     */
    public function testCannotBeCreatedFromOverlyLongString(): void
    {
        $longString = str_repeat('a', 151);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Topic cannot exceed 150 characters.');

        Topic::fromString($longString);
    }

    /**
     * Tests that creating a Topic with invalid characters throws an exception.
     *
     * @param string $invalidValue the invalid string to test
     */
    #[DataProvider('invalidCharacterProvider')]
    public function testCannotBeCreatedWithInvalidCharacters(string $invalidValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid cache topic format/');

        Topic::fromString($invalidValue);
    }

    /**
     * Provides a set of invalid strings for the invalid character test.
     *
     * @return array<string, array<string>>
     */
    public static function invalidCharacterProvider(): array
    {
        return [
            'with space' => ['invalid topic'],
            'with exclamation mark' => ['invalid!'],
            'with slash' => ['user/profile'],
            'with at symbol' => ['user@1'],
            'with curly braces' => ['{topic}'],
        ];
    }
}
