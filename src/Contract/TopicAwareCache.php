<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface TopicAwareCacheInterface
{
    public function subscribeTo(string $key, iterable $topics): void;

    public function unsubscribeFrom(string $key, iterable $topics): void;

    public function invalidateTopics(iterable $topics): bool;

    public function getSubscribedTopics(string $key): array;
}
