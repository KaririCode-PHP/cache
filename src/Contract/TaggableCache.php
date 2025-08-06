<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Extended interface for advanced operations.
 */
interface TaggableCache
{
    public function setWithTags(string $key, mixed $value, iterable $tags = []): bool;

    public function invalidateTags(iterable $tags): bool;

    public function getByTags(iterable $tags): iterable;

    public function getTags(): iterable;
}
