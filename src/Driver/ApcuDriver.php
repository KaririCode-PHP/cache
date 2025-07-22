<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlCalculatorTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A high-performance cache driver using the APCu PHP extension.
 * Implements tagging and atomic operations in user-space.
 */
final class ApcuDriver implements
    CacheDriver,
    AtomicCacheDriver,
    Taggable,
    CompareAndSwapAware
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use SerializationTrait;
    use TtlCalculatorTrait;

    private const TAG_INDEX_PREFIX = 'tag_index::';
    private const LOCK_PREFIX = 'lock::';
    private const LOCK_TIMEOUT = 5;

    private LoggerInterface $logger;

    public function __construct(
        private string $keyPrefix = 'cache::',
        ?LoggerInterface $logger = null
    ) {
        $this->validateEnvironment();
        $this->logger = $logger ?? new NullLogger();
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $fullKey = $this->keyPrefix . $key;
        $value = apcu_fetch($fullKey, $success);

        return $success ? $this->unserialize($value) : null;
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $fullKey = $this->keyPrefix . $key;
        $serializedValue = $this->serialize($value);
        $ttl = $this->calculateTtlFromExpiration($expiration);

        return apcu_store($fullKey, $serializedValue, $ttl);
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return apcu_exists($this->keyPrefix . $key);
    }

    public function delete(string $key): bool
    {
        return $this->deleteMultiple([$key]);
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return [];
        }
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $rawValues = apcu_fetch($prefixedKeys);
        if (!is_array($rawValues)) {
            $rawValues = [];
        }

        $results = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->keyPrefix . $key;
            $results[$key] = isset($rawValues[$prefixedKey]) ? $this->unserialize($rawValues[$prefixedKey]) : null;
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);
        if (empty($values)) {
            return true;
        }
        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->keyPrefix . (string) $key] = $this->serialize($value);
        }
        $ttl = $this->calculateTtlFromExpiration($expiration);
        $failedKeys = apcu_store($prefixedValues, null, $ttl);

        return empty($failedKeys);
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return true;
        }
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        foreach ($prefixedKeys as $fullKey) {
            $this->removeFromAllTags($fullKey);
        }
        $result = apcu_delete($prefixedKeys);

        return is_array($result) && empty($result);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $fullKey = $this->keyPrefix . $key;
        $ttlValue = $this->calculateTtl($ttl);

        $newValue = apcu_inc($fullKey, $step, $success);
        if ($success) {
            return $newValue;
        }

        $newValue = $initialValue + $step;
        if (apcu_add($fullKey, $newValue, $ttlValue)) {
            return $newValue;
        }

        $finalValue = apcu_inc($fullKey, $step, $success);
        if ($success) {
            return $finalValue;
        }
        throw CacheException::operationFailed('increment', $key);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $fullKey = $this->keyPrefix . $key;
        $serializedValue = $this->serialize($value);
        $ttlValue = $this->calculateTtl($ttl);

        return apcu_add($fullKey, $serializedValue, $ttlValue);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $fullKey = $this->keyPrefix . $key;

        $currentValue = apcu_fetch($fullKey);
        if ($currentValue === $this->serialize($expected)) {
            return apcu_store($fullKey, $this->serialize($value), $this->calculateTtl($ttl));
        }

        return false;
    }

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        if (!$this->set($key, $value, $this->calculateExpiration($ttl))) {
            return false;
        }

        $fullKey = $this->keyPrefix . $key;
        $tagStrings = array_map('strval', $tags);

        foreach ($tagStrings as $tag) {
            $tagKey = self::TAG_INDEX_PREFIX . $tag;
            $lockKey = self::LOCK_PREFIX . $tagKey;

            if (apcu_add($lockKey, 1, self::LOCK_TIMEOUT)) {
                try {
                    $keys = apcu_fetch($tagKey) ?: [];
                    if (is_array($keys) && !in_array($fullKey, $keys, true)) {
                        $keys[] = $fullKey;
                        apcu_store($tagKey, $keys, 0);
                    }
                } finally {
                    apcu_delete($lockKey);
                }
            }
        }

        return true;
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTagStrings(array_map('strval', $tags));
        $keysToDelete = [];
        $tagKeys = [];

        foreach ($tags as $tag) {
            $tagKey = self::TAG_INDEX_PREFIX . (string) $tag;
            $tagKeys[] = $tagKey;
            $keys = apcu_fetch($tagKey);
            if (is_array($keys)) {
                $keysToDelete = array_merge($keysToDelete, $keys);
            }
        }

        if (!empty($keysToDelete)) {
            apcu_delete(array_unique($keysToDelete));
        }
        apcu_delete($tagKeys);

        return true;
    }

    public function getByTags(array $tags): array
    {
        // ... (implementation is complex and depends on user-space logic)
        return [];
    }

    public function getTags(): array
    {
        $tags = [];
        $iterator = new \APCUIterator('#^' . preg_quote(self::TAG_INDEX_PREFIX) . '#', \APC_ITER_KEY);
        foreach ($iterator as $item) {
            if (is_array($item) && isset($item['key'])) {
                $tags[] = substr((string) $item['key'], strlen(self::TAG_INDEX_PREFIX));
            }
        }

        return $tags;
    }

    public function getCapabilities(): array
    {
        return [CacheCapabilities::ATOMIC_OPERATIONS, CacheCapabilities::TAGGING];
    }

    public function getStats(): DriverStats
    {
        $info = apcu_cache_info(true) ?: [];
        $smaInfo = apcu_sma_info() ?: [];

        return new MetricsSnapshot(
            'Apcu',
            isset($info['mem_size']) ? (int) $info['mem_size'] : null,
            isset($info['num_entries']) ? (int) $info['num_entries'] : null,
            phpversion('apcu') ?: null,
            ['cache_info' => $info, 'memory_info' => $smaInfo]
        );
    }

    private function validateEnvironment(): void
    {
        if (!extension_loaded('apcu')) {
            throw ConfigurationException::missingExtension('apcu');
        }
        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled in php.ini');
        }
        if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled for CLI (apc.enable_cli=1)');
        }
    }

    private function calculateTtlFromExpiration(?\DateTimeInterface $expiration): int
    {
        if (null === $expiration) {
            return 0;
        }
        $ttl = $expiration->getTimestamp() - time();

        return $ttl > 0 ? $ttl : 0;
    }

    private function removeFromAllTags(string $fullKey): void
    {
        $iterator = new \APCUIterator('#^' . preg_quote(self::TAG_INDEX_PREFIX) . '#');
        foreach ($iterator as $tagKey => $tagData) {
            if (is_array($tagData) && isset($tagData['value']) && is_array($tagData['value'])) {
                $keysInIndex = $tagData['value'];
                $index = array_search($fullKey, $keysInIndex, true);
                if (false !== $index) {
                    unset($keysInIndex[$index]);
                    apcu_store((string) $tagKey, array_values($keysInIndex));
                }
            }
        }
    }
}
