<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Provides tag validation functionality.
 *
 * This trait handles validation of cache tags, supporting both
 * Tag objects and string representations.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait TagValidationTrait
{
    /**
     * Validates an array of Tag objects with improved type safety.
     *
     * @param array<Tag> $tags Array of Tag objects
     *
     * @throws InvalidArgumentException if any tag is invalid
     */
    protected function validateTags(array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        foreach ($tags as $index => $tag) {
            if (!$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag(sprintf('Expected Tag object at index %d, got %s', $index, get_debug_type($tag)));
            }
        }
    }

    /**
     * Validates an array of tag strings with enhanced checks.
     *
     * @param array<string> $tags Array of tag strings
     *
     * @throws InvalidArgumentException if any tag is invalid
     */
    protected function validateTagStrings(array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        foreach ($tags as $index => $tag) {
            if (!is_string($tag)) {
                throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d must be a string, %s given', $index, get_debug_type($tag)));
            }

            $trimmedTag = trim($tag);
            if (empty($trimmedTag)) {
                throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d cannot be empty or whitespace-only', $index));
            }

            // Enhanced validation for tag format
            if (!$this->isValidTagFormat($trimmedTag)) {
                throw InvalidArgumentException::invalidTag(sprintf('Tag "%s" at index %d contains invalid characters. Only alphanumeric characters, dots, underscores, colons, and hyphens are allowed', $trimmedTag, $index));
            }

            // Check tag length
            if (strlen($trimmedTag) > 255) {
                throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d exceeds maximum length of 255 characters', $index));
            }
        }
    }

    /**
     * Converts mixed tag types to Tag objects safely.
     *
     * @param array<mixed> $tags Array of mixed tag types
     *
     * @throws InvalidArgumentException if conversion fails
     *
     * @return array<Tag> Array of Tag objects
     */
    protected function createTagObjects(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $tagObjects = [];

        foreach ($tags as $index => $tag) {
            try {
                if ($tag instanceof Tag) {
                    $tagObjects[] = $tag;
                } elseif (is_string($tag)) {
                    $trimmedTag = trim($tag);
                    if (empty($trimmedTag)) {
                        throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d cannot be empty', $index));
                    }
                    $tagObjects[] = new Tag($trimmedTag);
                } elseif (is_scalar($tag)) {
                    // Convert scalar types to string
                    $stringTag = (string) $tag;
                    $tagObjects[] = new Tag($stringTag);
                } else {
                    throw InvalidArgumentException::invalidTag(sprintf('Cannot convert tag at index %d of type %s to Tag object', $index, get_debug_type($tag)));
                }
            } catch (\Throwable $e) {
                throw InvalidArgumentException::invalidTag(sprintf('Failed to create Tag object at index %d: %s', $index, $e->getMessage()));
            }
        }

        return $tagObjects;
    }

    /**
     * Extracts string values from mixed tag array.
     *
     * @param array<mixed> $tags Mixed tag array
     *
     * @return array<string> Array of tag strings
     */
    protected function extractTagStrings(array $tags): array
    {
        return array_map(function (mixed $tag, int $index): string {
            if ($tag instanceof Tag) {
                return (string) $tag;
            }

            if (is_string($tag)) {
                return trim($tag);
            }

            if (is_scalar($tag)) {
                return (string) $tag;
            }

            throw InvalidArgumentException::invalidTag(sprintf('Cannot extract string from tag at index %d of type %s', $index, get_debug_type($tag)));
        }, $tags, array_keys($tags));
    }

    /**
     * Validates tag format using enhanced pattern matching.
     */
    private function isValidTagFormat(string $tag): bool
    {
        // Allow alphanumeric characters, dots, underscores, colons, and hyphens
        // Prevent tags that start or end with special characters
        return 1 === preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*[a-zA-Z0-9]$|^[a-zA-Z0-9]$/', $tag);
    }
}
