<?php

declare(strict_types=1);

namespace KaririCode\Cache\Decorator;

use KaririCode\Cache\Contract\Cache;

/**
 * Cache decorator with encryption capabilities.
 */
final class EncryptedCache implements Cache
{
    private Cache $cache;
    private string $encryptionKey;
    private string $cipher;

    public function __construct(Cache $cache, string $encryptionKey, string $cipher = 'AES-256-GCM')
    {
        $this->cache = $cache;
        $this->encryptionKey = $encryptionKey;
        $this->cipher = $cipher;

        if (!in_array($cipher, openssl_get_cipher_methods())) {
            throw new \InvalidArgumentException("Unsupported cipher: {$cipher}");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $encryptedValue = $this->cache->get($key, null);

        if (null === $encryptedValue) {
            return $default;
        }

        try {
            return $this->decrypt($encryptedValue);
        } catch (\Exception $e) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, $ttl = null): bool
    {
        try {
            $encryptedValue = $this->encrypt($value);

            return $this->cache->set($key, $encryptedValue, $ttl);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $encryptedValues = $this->cache->getMultiple($keys, null);
        $result = [];

        foreach ($encryptedValues as $key => $encryptedValue) {
            if (null === $encryptedValue) {
                $result[$key] = $default;
            } else {
                try {
                    $result[$key] = $this->decrypt($encryptedValue);
                } catch (\Exception $e) {
                    $result[$key] = $default;
                }
            }
        }

        return $result;
    }

    public function setMultiple(iterable $values, $ttl = null): bool
    {
        $encryptedValues = [];

        foreach ($values as $key => $value) {
            try {
                $encryptedValues[$key] = $this->encrypt($value);
            } catch (\Exception $e) {
                return false;
            }
        }

        return $this->cache->setMultiple($encryptedValues, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function getWithCallback(string $key, ?callable $callback = null, $ttl = null, array $tags = []): mixed
    {
        $encryptedValue = $this->cache->get($key, null);

        if (null !== $encryptedValue) {
            try {
                return $this->decrypt($encryptedValue);
            } catch (\Exception $e) {
                // Fall through to callback
            }
        }

        if (null === $callback) {
            return null;
        }

        $value = $callback();

        try {
            $encryptedValue = $this->encrypt($value);
            $this->cache->set($key, $encryptedValue, $ttl);
        } catch (\Exception $e) {
            // Encryption failed, but we still return the value
        }

        return $value;
    }

    public function getSimple(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    public function setWithTags(string $key, mixed $value, $ttl = null, array $tags = []): bool
    {
        try {
            $encryptedValue = $this->encrypt($value);

            return $this->cache->setWithTags($key, $encryptedValue, $ttl, $tags);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        // For increment operations, we need to decrypt, increment, and encrypt
        $current = $this->get($key, $initialValue);

        if (!is_int($current)) {
            $current = $initialValue;
        }

        $newValue = $current + $step;
        $this->set($key, $newValue, $ttl);

        return $newValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->cache->invalidateTags($tags);
    }

    public function getMetrics(): array
    {
        return $this->cache->getMetrics();
    }

    public function advanced(): \KaririCode\Cache\Contract\CacheItemPool
    {
        return $this->cache->advanced();
    }

    public function warm(array $data, $ttl = null): void
    {
        $encryptedData = [];

        foreach ($data as $key => $value) {
            try {
                $encryptedData[$key] = $this->encrypt($value);
            } catch (\Exception $e) {
                // Skip items that can't be encrypted
                continue;
            }
        }

        $this->cache->warm($encryptedData, $ttl);
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->cache->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->cache->invalidateTopics($topics);
    }

    private function encrypt(mixed $value): string
    {
        $serialized = serialize($value);
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $tag = '';
        $encrypted = openssl_encrypt($serialized, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $encrypted) {
            throw new \Exception('Encryption failed');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    private function decrypt(string $encryptedValue): mixed
    {
        $data = base64_decode($encryptedValue);

        if (false === $data) {
            throw new \Exception('Invalid encrypted data');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, 16); // GCM tag is 16 bytes
        $encrypted = substr($data, $ivLength + 16);

        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $decrypted) {
            throw new \Exception('Decryption failed');
        }

        return unserialize($decrypted);
    }
}
