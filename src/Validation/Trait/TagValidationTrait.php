<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Operation\Factory\TagNormalizer;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Trait providing tag validation and normalization utilities.
 */
trait TagValidationTrait
{
    protected function normalizeTags(array $tags): array
    {
        return TagNormalizer::normalize($tags);
    }

    protected function validateTags(array $tags): void
    {
        if (empty($tags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        foreach ($tags as $index => $tag) {
            if (!$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag(sprintf('Expected Tag object at index %d, got %s', $index, get_debug_type($tag)));
            }
        }
    }

    protected function validateMixedTags(array $tags): void
    {
        if (empty($tags)) {
            throw InvalidArgumentException::emptyTagList();
        }

        foreach ($tags as $index => $tag) {
            if (!is_string($tag) && !$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag(sprintf('Tag at index %d must be string or Tag object, %s given', $index, get_debug_type($tag)));
            }

            if (is_string($tag) && empty(trim($tag))) {
                throw InvalidArgumentException::invalidTag(sprintf('String tag at index %d cannot be empty', $index));
            }
        }
    }

    protected function extractTagStrings(array $tags): array
    {
        return TagNormalizer::toStrings($tags);
    }
}
