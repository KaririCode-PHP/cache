<?php

declare(strict_types=1);

namespace KaririCode\Cache\Decorator;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Exception\SecurityException;
use KaririCode\Cache\Metrics\CacheMetrics;

/**
 * Cache decorator with encryption capabilities.
 *
 * This decorator encrypts values before sending them to the cache and
 * decrypts them upon retrieval, following the Decorator Pattern.
 * This demonstrates Composition over Inheritance and Separation of Concerns.
 *
 * NOTE: Atomic operations (increment, decrement, etc.) are unsupported
 * as atomicity cannot be guaranteed with the encrypt-decrypt cycle.
 * This class follows the Fail Fast principle by throwing an exception
 * when these methods are called.
 */
final class EncryptedCache implements Cache
{
    private readonly int $ivLength;
    private readonly int $tagLength;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $encryptionKey,
        private readonly string $cipher = 'AES-256-GCM'
    ) {
        if (32 !== mb_strlen($this->encryptionKey, '8bit')) {
            throw InvalidArgumentException::invalidValue('Encryption key must be 32 bytes long for AES-256-GCM.');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        if (false === $ivLength) {
            throw InvalidArgumentException::invalidValue("Unsupported or invalid cipher: {$this->cipher}.");
        }

        $this->ivLength = $ivLength;
        $this->tagLength = 16;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $encryptedValue = $this->cache->get($key);

        if (!is_string($encryptedValue) || empty($encryptedValue)) {
            return $default;
        }

        try {
            return $this->decrypt($encryptedValue);
        } catch (\Exception) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        try {
            $encryptedValue = $this->encrypt($value);

            return $this->cache->set($key, $encryptedValue, $ttl);
        } catch (\Exception) {
            return false;
        }
    }

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        try {
            $encryptedValue = $this->encrypt($value);

            return $this->cache->setWithTags($key, $encryptedValue, $tags, $ttl);
        } catch (\Exception) {
            return false;
        }
    }

    public function getMultiple(iterable $keys): iterable
    {
        $encryptedValues = $this->cache->getMultiple($keys);
        $result = [];

        foreach ($encryptedValues as $key => $encryptedValue) {
            if (!is_string($encryptedValue) || empty($encryptedValue)) {
                $result[$key] = null;
            } else {
                try {
                    $result[$key] = $this->decrypt($encryptedValue);
                } catch (\Exception) {
                    $result[$key] = null;
                }
            }
        }

        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        try {
            $encryptedValues = [];
            foreach ($values as $key => $value) {
                $encryptedValues[$key] = $this->encrypt($value);
            }

            return $this->cache->setMultiple($encryptedValues, $ttl);
        } catch (\Exception) {
            return false;
        }
    }

    public function warm(array $data, \DateInterval|int|null $ttl = null): void
    {
        try {
            $encryptedData = [];
            foreach ($data as $key => $value) {
                $encryptedData[$key] = $this->encrypt($value);
            }
            $this->cache->warm($encryptedData, $ttl);
        } catch (\Exception) {
            // Log error but avoid throwing exception during a warm-up.
        }
    }

    // ========================================================================
    // Delegated Methods
    // ========================================================================

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function getName(): string
    {
        return $this->cache->getName() . ' (Encrypted)';
    }

    public function isHealthy(): bool
    {
        return $this->cache->isHealthy();
    }

    public function maintenance(array $options = []): bool
    {
        return $this->cache->maintenance($options);
    }

    public function getMetrics(): CacheMetrics
    {
        return $this->cache->getMetrics();
    }

    public function getWithCallback(string $key, ?callable $callback = null, \DateInterval|int|null $ttl = null, array $tags = []): mixed
    {
        $value = $this->get($key);
        if (null !== $value) {
            return $value;
        }
        if ($callback) {
            $value = $callback();
            $this->setWithTags($key, $value, $tags, $ttl);

            return $value;
        }

        return null;
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->cache->invalidateTags($tags);
    }

    public function getByTags(array $tags): array
    {
        $encryptedItems = $this->cache->getByTags($tags);
        $decryptedItems = [];
        foreach ($encryptedItems as $key => $value) {
            if (is_string($value) && !empty($value)) {
                try {
                    $decryptedItems[$key] = $this->decrypt($value);
                } catch (\Exception) {
                    // Skip items that fail to decrypt
                }
            }
        }

        return $decryptedItems;
    }

    public function getTags(): array
    {
        return $this->cache->getTags();
    }

    public function subscribeTo(string $key, array $topics): void
    {
        $this->cache->subscribeTo($key, $topics);
    }

    public function unsubscribeFrom(string $key, array $topics): void
    {
        $this->cache->unsubscribeFrom($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->cache->invalidateTopics($topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        return $this->cache->getSubscribedTopics($key);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->cache->getSubscribersForTopic($topic);
    }

    // ========================================================================
    // Unsupported Atomic Operations
    // ========================================================================

    private function createUnsupportedException(string $operation): CacheException
    {
        return CacheException::operationFailed(
            $operation,
            'any',
            new \RuntimeException('Atomic operations are not supported on an encrypted cache.')
        );
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        throw $this->createUnsupportedException('increment');
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        throw $this->createUnsupportedException('decrement');
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        throw $this->createUnsupportedException('add');
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        throw $this->createUnsupportedException('compareAndSwap');
    }

    // ========================================================================
    // Encryption & Decryption Logic
    // ========================================================================

    private function encrypt(mixed $value): string
    {
        $serialized = serialize($value);
        $iv = openssl_random_pseudo_bytes($this->ivLength);

        $tag = '';
        $encrypted = openssl_encrypt(
            $serialized,
            $this->cipher,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            $this->tagLength
        );

        if (false === $encrypted) {
            throw SecurityException::encryptionFailed('data');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    private function decrypt(string $encryptedValue): mixed
    {
        $data = base64_decode($encryptedValue, true);

        if (false === $data || mb_strlen($data, '8bit') < ($this->ivLength + $this->tagLength)) {
            throw SecurityException::decryptionFailed('data', new \RuntimeException('Invalid encrypted data format or length.'));
        }

        $iv = substr($data, 0, $this->ivLength);
        $tag = substr($data, $this->ivLength, $this->tagLength);
        $encrypted = substr($data, $this->ivLength + $this->tagLength);

        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $decrypted) {
            throw SecurityException::decryptionFailed('data', new \RuntimeException('Decryption failed. Data may be corrupt or the key is wrong.'));
        }

        return unserialize($decrypted);
    }
}
