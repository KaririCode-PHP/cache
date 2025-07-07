<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Contract\Cache;

/**
 * Specialized cache for user session data.
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

    public function getSessionData(string $sessionId): ?array
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->get($key);
    }

    public function setSessionData(string $sessionId, array $data, ?int $ttl = null): bool
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->set($key, $data, $ttl ?? $this->defaultTtl);
    }

    public function updateSessionData(string $sessionId, array $data, ?int $ttl = null): bool
    {
        $key = $this->getSessionKey($sessionId);
        $existing = $this->cache->get($key, []);
        $merged = array_merge($existing, $data);

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
        $data = $this->cache->get($key);

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
