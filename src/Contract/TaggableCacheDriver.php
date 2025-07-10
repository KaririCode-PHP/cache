<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface TaggableCacheDriver extends CacheDriver
{
    public function invalidateTags(array $tags): bool;
}
