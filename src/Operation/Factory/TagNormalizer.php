<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation\Factory;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Normalizes input for tag operations, converting strings to Tag objects.
 *
 * This class provides methods to ensure that tags are consistently represented
 * as Tag objects, improving performance by caching normalized tags.
 */
final class TagNormalizer
{
    /**
     * Internal cache for performance optimization.
     *
     * @var array<string, Tag>
     */
    private static array $tagCache = [];

    /**
     * Normalizes mixed input to Tag objects with cache.
     *
     * @param array<string|Tag> $tags Hybrid input
     *
     * @throws InvalidArgumentException
     *
     * @return array<Tag>
     */
    public static function normalize(array $tags): array
    {
        if (empty($tags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        $normalized = [];

        foreach ($tags as $index => $tag) {
            $normalized[] = self::normalizeTag($tag, $index);
        }

        return $normalized;
    }

    /**
     * Normalizes a single tag with cache for performance.
     *
     * @param int $index For error reporting
     */
    private static function normalizeTag(string|Tag $tag, int $index): Tag
    {
        // 1. Guard clause for Tag type
        if ($tag instanceof Tag) {
            return $tag;
        }

        // 2. Guard clause for invalid types
        if (!is_string($tag)) {
            throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d must be string or Tag object, %s given', $index, get_debug_type($tag)));
        }

        // From here, we know $tag is a string
        $trimmed = trim($tag);
        if (empty($trimmed)) {
            throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d cannot be empty', $index));
        }

        // Cache logic and object creation
        if (isset(self::$tagCache[$trimmed])) {
            return self::$tagCache[$trimmed];
        }

        $tagObject = new Tag($trimmed);
        self::$tagCache[$trimmed] = $tagObject;

        return $tagObject;
    }

    /**
     * Converts array of Tag to strings (for legacy drivers).
     *
     * @param array<Tag> $tags
     *
     * @return array<string>
     */
    public static function toStrings(array $tags): array
    {
        return array_map(fn (Tag $tag): string => (string) $tag, $tags);
    }

    /**
     * Helper for quickly creating multiple tags.
     *
     * @return array<Tag>
     */
    public static function create(string ...$tagStrings): array
    {
        return self::normalize($tagStrings);
    }

    /**
     * Clears internal cache (for tests or memory saving).
     */
    public static function clearCache(): void
    {
        self::$tagCache = [];
    }
}
