<?php

declare(strict_types=1);

namespace KaririCode\Cache\Decorator;

use KaririCode\Cache\Builder\CacheOperationBuilder;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\AdvancedCache;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Exception\SecurityException;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Service\SerializationService;

/**
 * Decorator that adds a layer of transparent encryption to a Cache instance.
 *
 * This class follows the Decorator pattern, wrapping a Cache object to encrypt
 * all outgoing values and decrypt all incoming values. It is a true drop-in
 * replacement, implementing the same interfaces as the Cache facade.
 *
 * NOTE: Atomic operations (increment, decrement) are unsupported as atomicity
 * cannot be guaranteed with the encrypt-decrypt cycle. This class follows the
 * Fail-Fast principle by throwing an exception when these methods are called.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class EncryptedCacheDecorator implements AdvancedCache, Taggable, TopicSubscribable
{
    private readonly int $ivLength;
    private readonly int $tagLength;

    /**
     * @param Cache $cache the cache instance to decorate
     * @param SerializationService $serializer the central serialization service
     * @param string $encryptionKey the 32-byte encryption key for AES-256-GCM
     * @param string $cipher the encryption cipher to use
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly SerializationService $serializer,
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
        $this->tagLength = 16; // Standard for GCM
    }

    // --- Encrypted Operations ---

    public function get(string $key, mixed $default = null): mixed
    {
        $encryptedValue = $this->cache->get($key);

        if (!is_string($encryptedValue) || empty($encryptedValue)) {
            return $default;
        }

        try {
            return $this->decrypt($encryptedValue);
        } catch (\Exception) {
            // If decryption fails, treat it as a miss.
            return $default;
        }
    }

    public function set(string $key, mixed $value): bool
    {
        try {
            $encryptedValue = $this->encrypt($value);

            return $this->cache->set($key, $encryptedValue);
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

    public function setMultiple(iterable $values): bool
    {
        try {
            $encryptedValues = [];
            foreach ($values as $key => $value) {
                $encryptedValues[$key] = $this->encrypt($value);
            }

            return $this->cache->setMultiple($encryptedValues);
        } catch (\Exception) {
            return false;
        }
    }

    public function setWithTags(string $key, mixed $value, iterable $tags = []): bool
    {
        try {
            $encryptedValue = $this->encrypt($value);

            return $this->cache->setWithTags($key, $encryptedValue, $tags);
        } catch (\Exception) {
            return false;
        }
    }

    public function getByTags(iterable $tags): iterable
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

    // --- Delegated Methods (No value manipulation) ---

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple($keys);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->cache->invalidateTags($tags);
    }

    public function getTags(): iterable
    {
        return $this->cache->getTags();
    }

    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->cache->subscribeTo($key, $topics);
    }

    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        $this->cache->unsubscribeFrom($key, $topics);
    }

    public function invalidateTopics(iterable $topics): bool
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

    public function getName(): string
    {
        return $this->cache->getName() . ' (Encrypted)';
    }

    public function isHealthy(): bool
    {
        return $this->cache->isHealthy();
    }

    public function getMetrics(): CacheMetrics
    {
        return $this->cache->getMetrics();
    }

    public function getCapabilities(): array
    {
        // Remove atomic capability as it's not supported by this decorator.
        $capabilities = $this->cache->getCapabilities();

        return array_diff($capabilities, [CacheCapabilities::ATOMIC_OPERATIONS]);
    }

    // --- Fluent Builder (Delegated) ---

    public function with(): CacheOperationBuilder
    {
        // The builder itself is not decorated, as encryption is a global setting.
        // A more advanced implementation could return a decorated builder.
        return $this->cache->with();
    }

    // --- Unsupported Atomic Operations ---

    private function createUnsupportedException(string $operation): CacheException
    {
        return CacheException::operationFailed(
            $operation,
            'any',
            new \RuntimeException('Atomic operations are not supported on an encrypted cache.')
        );
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        throw $this->createUnsupportedException('increment');
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        throw $this->createUnsupportedException('decrement');
    }

    public function add(string $key, mixed $value): bool
    {
        // This could be emulated, but it would not be atomic.
        // Fail-fast is safer.
        throw $this->createUnsupportedException('add');
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        throw $this->createUnsupportedException('compareAndSwap');
    }

    // --- Private Encryption & Decryption Logic ---

    private function encrypt(mixed $value): string
    {
        $serialized = $this->serializer->serialize($value);
        $iv = openssl_random_pseudo_bytes($this->ivLength);

        $tag = '';
        $encrypted = openssl_encrypt(
            $serialized,
            $this->cipher,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // AAD is not used here for simplicity
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

        return $this->serializer->unserialize($decrypted);
    }
}
