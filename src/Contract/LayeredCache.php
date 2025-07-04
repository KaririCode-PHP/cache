<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface LayeredCache extends CacheItemPool
{
    public function addLayer(CacheItemPool $cachePool, int $priority): void;
}
