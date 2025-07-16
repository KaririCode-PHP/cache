<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Contract\Cache;

/**
 * A specialized cache helper for implementing rate limiting logic.
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

        return $this->cache->increment($key, 1, 1, $windowSeconds);
    }

    public function getRemainingAttempts(string $identifier, int $maxAttempts, int $windowSeconds): int
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);
        $current = $this->cache->get($key, 0);

        return (int) max(0, $maxAttempts - $current);
    }

    public function getResetTime(string $identifier, int $windowSeconds): int
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);

        return $this->cache->has($key) ? (time() + $windowSeconds) : 0;
    }

    public function reset(string $identifier, int $windowSeconds): bool
    {
        $key = $this->getRateLimitKey($identifier, $windowSeconds);

        return $this->cache->delete($key);
    }

    /**
     * @return array{allowed: bool, attempts: int, remaining: int, reset_time: int}
     */
    public function checkAndRecordAttempt(string $identifier, int $maxAttempts, int $windowSeconds): array
    {
        if (!$this->isAllowed($identifier, $maxAttempts, $windowSeconds)) {
            $current = $this->cache->get($this->getRateLimitKey($identifier, $windowSeconds), $maxAttempts);

            // Validate the type before casting to ensure type safety.
            $safeCurrent = is_numeric($current) ? (int) $current : $maxAttempts;

            return [
                'allowed' => false,
                'attempts' => $safeCurrent,
                'remaining' => 0,
                'reset_time' => $this->getResetTime($identifier, $windowSeconds),
            ];
        }

        $newCount = $this->recordAttempt($identifier, $windowSeconds);

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
