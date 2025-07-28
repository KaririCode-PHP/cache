<?php

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Config\DTO\FeatureConfig;
use KaririCode\Cache\Contract\Cache;

final class RateLimitingCache
{
    public function __construct(
        private Cache $cache,
        private FeatureConfig $features
    ) {
    }

    public function allow(string $key, int $limit, int $window): bool
    {
        // Use stampede protection if enabled
        if ($this->features->stampedeProtection) {
            return $this->cache->getWithCallback(
                $key,
                fn () => $this->initializeCounter(),
                $window
            ) <= $limit;
        }

        // Fallback without stampede protection
        $current = $this->cache->get($key, 0);
        if ($current >= $limit) {
            return false;
        }

        $this->cache->increment($key, 1, 1, $window);

        return true;
    }

    private function initializeCounter(): int
    {
        return 1;
    }
}
