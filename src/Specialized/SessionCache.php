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

    public function __construct(
        Cache $cache,
        string $prefix = 'session:'
    ) {
        $this->cache = $cache;
        $this->prefix = $prefix;
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
    public function setSessionData(string $sessionId, array $data): bool
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->set($key, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSessionData(string $sessionId, array $data): bool
    {
        $key = $this->getSessionKey($sessionId);

        $existingData = $this->getSessionData($sessionId) ?? [];

        $merged = array_merge($existingData, $data);

        return $this->cache->set($key, $merged);
    }

    public function deleteSession(string $sessionId): bool
    {
        $key = $this->getSessionKey($sessionId);

        return $this->cache->delete($key);
    }

    public function touchSession(string $sessionId): bool
    {
        $key = $this->getSessionKey($sessionId);
        $data = $this->getSessionData($sessionId);

        if (null === $data) {
            return false;
        }

        return $this->cache->set($key, $data);
    }

    public function getSessionValue(string $sessionId, string $key, mixed $default = null): mixed
    {
        $sessionData = $this->getSessionData($sessionId);

        return $sessionData[$key] ?? $default;
    }

    public function setSessionValue(string $sessionId, string $key, mixed $value): bool
    {
        return $this->updateSessionData($sessionId, [$key => $value]);
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
