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
     * Validates an array of Tag objects.
     *
     * @param array<Tag> $tags Array of Tag objects
     *
     * @throws InvalidArgumentException if any tag is invalid
     */
    protected function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag('Expected Tag object, got ' . get_debug_type($tag));
            }
        }
    }

    /**
     * Validates an array of tag strings.
     *
     * @param array<string> $tags Array of tag strings
     *
     * @throws InvalidArgumentException if any tag is invalid
     */
    protected function validateTagStrings(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw InvalidArgumentException::invalidTag('Tag must be a string, ' . gettype($tag) . ' given');
            }

            if (empty(trim($tag))) {
                throw InvalidArgumentException::invalidTag('Tag cannot be empty');
            }

            // Validate tag format
            if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $tag)) {
                throw InvalidArgumentException::invalidTag("Tag contains invalid characters: '{$tag}'");
            }
        }
    }

    /**
     * Converts tag strings to Tag objects.
     *
     * @param array<string> $tagStrings Array of tag strings
     *
     * @return array<Tag> Array of Tag objects
     */
    protected function createTagObjects(array $tagStrings): array
    {
        $this->validateTagStrings($tagStrings);

        return array_map(
            fn (string $tag) => new Tag($tag),
            $tagStrings
        );
    }
}
