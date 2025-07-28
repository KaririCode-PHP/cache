<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Contract\Cache;

/**
 * A specialized cache helper for managing user session data.
 */
final class SessionCache
{
    private Cache $cache;
    private string $prefix;
    private int $defaultTtl;

    public function __construct(Cache $cache, string $prefix = 'session:', int $defaultTtl = 3600)
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSessionData(string $sessionId): ?array
    {
        $key = $this->getSessionKey($sessionId);
        $data = $this->cache->get($key);

        // FIX: Validate that the data retrieved from cache is actually an array.
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setSessionData(string $sessionId, array $data, ?int $ttl = null): bool
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->set($key, $data, $ttl ?? $this->defaultTtl);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSessionData(string $sessionId, array $data, ?int $ttl = null): bool
    {
        $key = $this->getSessionKey($sessionId);

        // FIX: Use the now-safe getSessionData() method to ensure we start with an array.
        $existingData = $this->getSessionData($sessionId) ?? [];

        $merged = array_merge($existingData, $data);

        return $this->cache->set($key, $merged, $ttl ?? $this->defaultTtl);
    }

    public function deleteSession(string $sessionId): bool
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->delete($key);
    }

    public function touchSession(string $sessionId, ?int $ttl = null): bool
    {
        $key = $this->getSessionKey($sessionId);
        $data = $this->getSessionData($sessionId); // Use the safe method

        if (null === $data) {
            return false;
        }

        return $this->cache->set($key, $data, $ttl ?? $this->defaultTtl);
    }

    public function getSessionValue(string $sessionId, string $key, mixed $default = null): mixed
    {
        $sessionData = $this->getSessionData($sessionId);

        return $sessionData[$key] ?? $default;
    }

    public function setSessionValue(string $sessionId, string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->updateSessionData($sessionId, [$key => $value], $ttl);
    }

    public function unsetSessionValue(string $sessionId, string $key): bool
    {
        $sessionData = $this->getSessionData($sessionId);
        if (null === $sessionData) {
            return false;
        }

        unset($sessionData[$key]);

        return $this->setSessionData($sessionId, $sessionData);
    }

    public function sessionExists(string $sessionId): bool
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->has($key);
    }

    private function getSessionKey(string $sessionId): string
    {
        return $this->prefix . hash('sha256', $sessionId);
    }
}
