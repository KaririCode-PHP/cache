<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Contract\Cache;

/**
 * Specialized cache for rate limiting.
 */
final class RateLimitCache
{
    private Cache $cache;
    private string $prefix;

    public function __construct(Cache $cache, string $prefix = 'rate_limit:')
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
    }

    public function isAllowed(string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);
        $current = $this->cache->get($key, 0);

        return $current < $maxAttempts;
    }

    public function recordAttempt(string $identifier, int $windowSeconds): int
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);

        return $this->cache->increment($key, 1, 0, $windowSeconds);
    }

    public function getRemainingAttempts(string $identifier, int $maxAttempts, int $windowSeconds): int
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);
        $current = $this->cache->get($key, 0);

        return max(0, $maxAttempts - $current);
    }

    public function getResetTime(string $identifier, int $windowSeconds): int
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);

        if (!$this->cache->has($key)) {
            return 0;
        }

        return time() + $windowSeconds;
    }

    public function reset(string $identifier, int $windowSeconds): bool
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);

        return $this->cache->delete($key);
    }

    public function checkAndRecordAttempt(string $identifier, int $maxAttempts, int $windowSeconds): array
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);
        $current = $this->cache->get($key, 0);

        if ($current >= $maxAttempts) {
            return [
                'allowed' => false,
                'attempts' => $current,
                'remaining' => 0,
                'reset_time' => $this->getResetTime($identifier, $windowSeconds),
            ];
        }

        $newCount = $this->cache->increment($key, 1, 0, $windowSeconds);

        return [
            'allowed' => true,
            'attempts' => $newCount,
            'remaining' => max(0, $maxAttempts - $newCount),
            'reset_time' => $this->getResetTime($identifier, $windowSeconds),
        ];
    }

    private function getRateLimitKey(string $identifier, int $windowSeconds): string
    {
        $timeWindow = floor(time() / $windowSeconds);

        return $this->prefix . hash('sha256', $identifier . ':' . $timeWindow);
    }
}
