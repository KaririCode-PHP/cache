<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface AtomicCacheDriver extends CacheDriver
{
    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int;

    public function add(string $key, mixed $value, $ttl = null): bool;
}
