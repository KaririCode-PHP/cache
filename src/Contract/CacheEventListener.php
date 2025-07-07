<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheEventListener
{
    public function onCacheHit(string $key, mixed $value): void;

    public function onCacheMiss(string $key): void;

    public function onCacheSet(string $key, mixed $value): void;

    public function onCacheDelete(string $key): void;

    public function onCacheClear(): void;
}
