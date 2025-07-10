<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface TopicAwareCacheDriver extends CacheDriver
{
    public function subscribeTo(string $key, array $topics): void;

    public function invalidateTopics(array $topics): bool;
}
