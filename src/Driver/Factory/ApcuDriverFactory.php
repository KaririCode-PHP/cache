<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats as DriverStatsContract;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise-grade APCu driver with enhanced security and cross-process safety.
 *
 * @see https://www.php.net/manual/en/book.apcu.php APCu Documentation
 */
final class ApcuDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    private string $keyPrefix;
    private LoggerInterface $logger;
    private bool $memoryWarningIssued = false;
    private int $operationCount = 0;

    private const TAG_INDEX_PREFIX = 'tag_index::';
    private const LOCK_PREFIX = 'lock::';
    private const METADATA_PREFIX = 'meta::';
    private const MAX_KEY_LENGTH = 200;
    private const MAX_VALUE_SIZE = 64 * 1024 * 1024;
    private const MAX_BATCH_SIZE = 100;
    private const LOCK_TIMEOUT = 5;
    private const MEMORY_WARNING_THRESHOLD = 0.9;
    private const CLEANUP_INTERVAL = 1000;

    public function __construct(string $keyPrefix = 'cache::', ?LoggerInterface $logger = null)
    {
        $this->validateEnvironment();
        $this->keyPrefix = $this->sanitizePrefix($keyPrefix);
        $this->logger = $logger ?? new NullLogger();
        $this->logInitialization();
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $this->incrementOperations();
        $fullKey = $this->keyPrefix . $key;
        $success = false;
        $value = apcu_fetch($fullKey, $success);
        if (!$success) {
            return null;
        }

        return $this->unserialize($value);
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();
        $this->checkMemoryUsage();

        $fullKey = $this->keyPrefix . $key;
        $serializedValue = $this->serialize($value);
        $ttl = $this->calculateTtl($expiration);

        $result = apcu_store($fullKey, $serializedValue, $ttl);
        if ($result) {
            $this->updateMetadata($key, strlen($serializedValue));
        }

        return $result;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();

        return apcu_exists($this->keyPrefix . $key);
    }

    public function delete(string $key): bool
    {
        return $this->deleteMultiple([$key]);
    }

    public function clear(): bool
    {
        $this->incrementOperations();
        $prefixes = [$this->keyPrefix, self::TAG_INDEX_PREFIX, self::LOCK_PREFIX, self::METADATA_PREFIX];
        $clearedCount = $this->clearByPrefix($prefixes);
        $this->operationCount = 0;
        $this->memoryWarningIssued = false;
        $this->logger->info('APCu cache cleared', ['keys_cleared' => $clearedCount]);

        return true;
    }

    /**
     * @param string[] $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return [];
        }

        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $rawValues = apcu_fetch($prefixedKeys);
        $results = [];

        if (!is_array($rawValues)) {
            $rawValues = [];
        }

        foreach ($keys as $key) {
            $prefixedKey = $this->keyPrefix . $key;
            if (array_key_exists($prefixedKey, $rawValues)) {
                $value = $rawValues[$prefixedKey];
                $results[$key] = $this->unserialize($value);
            } else {
                $results[$key] = null;
            }
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);
        if (empty($values)) {
            return true;
        }
        $this->checkMemoryUsage();

        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->keyPrefix . $key] = $this->serialize($value);
        }

        $ttl = $this->calculateTtl($expiration);
        $failedKeys = apcu_store($prefixedValues, null, $ttl);
        $success = empty($failedKeys);

        if ($success) {
            foreach (array_keys($values) as $key) {
                if (isset($prefixedValues[$this->keyPrefix . $key])) {
                    $this->updateMetadata($key, strlen($prefixedValues[$this->keyPrefix . $key]));
                }
            }
        }

        return $success;
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
        foreach ($keys as $key) {
            $this->deleteMetadata($key);
        }

        return is_array($result) ? empty($result) : (bool) $result;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|\DateTimeInterface|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;
        $ttlValue = $this->calculateTtl($ttl);

        if (!apcu_exists($fullKey)) {
            if (!apcu_add($fullKey, $initialValue, $ttlValue)) {
                // Race condition, fall through to increment.
            }
        }

        $success = false;
        $result = apcu_inc($fullKey, $step, $success);

        if (false === $result) {
            apcu_store($fullKey, $initialValue, $ttlValue);
            $result = apcu_inc($fullKey, $step, $success);
            if (false === $result) {
                throw CacheException::operationFailed('increment', $key);
            }
        }

        return $result;
    }

    public function add(string $key, mixed $value, \DateInterval|int|\DateTimeInterface|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();
        $this->checkMemoryUsage();

        $fullKey = $this->keyPrefix . $key;
        $serializedValue = $this->serialize($value);
        $ttlValue = $this->calculateTtl($ttl);

        $result = apcu_add($fullKey, $serializedValue, $ttlValue);
        if ($result) {
            $this->updateMetadata($key, strlen($serializedValue));
        }

        return $result;
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|\DateTimeInterface|null $ttl = null): bool
    {
        $this->validateKey($key);
        $fullKey = $this->keyPrefix . $key;
        $lockKey = self::LOCK_PREFIX . $key;
        $ttlValue = $this->calculateTtl($ttl);

        if (!apcu_add($lockKey, 1, self::LOCK_TIMEOUT)) {
            return false; // Could not acquire lock
        }

        try {
            $currentSerialized = apcu_fetch($fullKey);
            $expectedSerialized = $this->serialize($expected);

            if ($currentSerialized === $expectedSerialized) {
                $valueSerialized = $this->serialize($value);

                return apcu_store($fullKey, $valueSerialized, $ttlValue);
            }

            return false;
        } finally {
            apcu_delete($lockKey);
        }
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        $this->incrementOperations();
        if (empty($tags)) {
            return true;
        }

        return $this->invalidateByPrefix(self::TAG_INDEX_PREFIX, $tags);
    }

    /** @return string[] */
    public function getTags(): array
    {
        $tags = [];
        $iterator = new \APCUIterator('#^' . preg_quote(self::TAG_INDEX_PREFIX) . '#', \APC_ITER_KEY);
        foreach ($iterator as $item) {
            if (isset($item['key']) && is_string($item['key'])) {
                $tags[] = substr($item['key'], strlen(self::TAG_INDEX_PREFIX));
            }
        }

        return $tags;
    }

    /** @return list<string> */
    public function getCapabilities(): array
    {
        return [CacheCapabilities::ATOMIC_OPERATIONS, CacheCapabilities::TAGGING];
    }

    public function getStats(): DriverStatsContract
    {
        $info = apcu_cache_info(true) ?: [];
        $smaInfo = apcu_sma_info() ?: [];
        /** @var string|false $phpVersion */
        $phpVersion = phpversion('apcu');

        return new DriverStats(
            'Apcu',
            isset($info['mem_size']) ? (int) $info['mem_size'] : null,
            isset($info['num_entries']) ? (int) $info['num_entries'] : null,
            $phpVersion ?: null,
            ['cache_info' => $info, 'memory_info' => $smaInfo]
        );
    }

    /** @return array{status: string} */
    public function healthCheck(): array
    {
        $testKey = $this->keyPrefix . 'health_check_' . uniqid('', true);
        $testValue = 'ok';
        if (apcu_store($testKey, $testValue, 10)) {
            $read = apcu_fetch($testKey);
            apcu_delete($testKey);
            if ($read === $testValue) {
                return ['status' => 'healthy'];
            }
        }

        return ['status' => 'unhealthy'];
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

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }
        if (preg_match('/[\s\x00-\x1F\x7F]/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }
    }

    private function validateKeys(array $keys): void
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Batch size exceeds limit');
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('All keys must be strings');
            }
            $this->validateKey($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        if (strlen($this->serialize($value)) > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidValue('Value size exceeds limit');
        }
    }

    private function validateMultipleValues(array $values): void
    {
        if (count($values) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidValue('Too many values');
        }
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('All keys must be strings');
            }
            $this->validateKey($key);
            $this->validateValue($value);
        }
    }

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || empty($tag)) {
                throw InvalidArgumentException::invalidTag((string) $tag);
            }
        }
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Step cannot be zero');
        }
    }

    private function sanitizePrefix(string $prefix): string
    {
        $sanitized = (string) preg_replace('/[^\w\-\.\:]/', '_', $prefix);

        return substr($sanitized, 0, 50);
    }

    private function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed(get_debug_type($value), $e);
        }
    }

    private function unserialize(mixed $data): mixed
    {
        if (!is_string($data)) {
            return $data;
        }
        try {
            return unserialize($data);
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('apcu', $e);
        }
    }

    private function calculateTtl(\DateInterval|int|\DateTimeInterface|null $ttl): int
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }
        if ($ttl instanceof \DateTimeInterface) {
            return $ttl->getTimestamp() - time();
        }
        if (is_int($ttl)) {
            return $ttl > 0 ? $ttl : 0;
        }

        return 0;
    }

    private function incrementOperations(): void
    {
        ++$this->operationCount;
        if (0 === $this->operationCount % self::CLEANUP_INTERVAL) {
            $this->performMaintenance();
        }
    }

    private function checkMemoryUsage(): void
    {
        $memoryInfo = $this->getMemoryInfo();
        if ($memoryInfo['usage_percent'] > self::MEMORY_WARNING_THRESHOLD * 100 && !$this->memoryWarningIssued) {
            $this->logger->warning('APCu memory usage high', (array) $memoryInfo);
            $this->memoryWarningIssued = true;
        }
    }

    /** @return array<string, float|int> */
    private function getMemoryInfo(): array
    {
        $smaInfo = apcu_sma_info() ?: [];
        $totalMemory = $smaInfo['seg_size'] ?? 1;
        $availableMemory = $smaInfo['avail_mem'] ?? 0;
        $usedMemory = $totalMemory - $availableMemory;

        return [
            'total_memory' => (int) $totalMemory,
            'available_memory' => (int) $availableMemory,
            'used_memory' => (int) $usedMemory,
            'usage_percent' => round(($usedMemory / ($totalMemory ?: 1)) * 100, 2),
        ];
    }

    private function updateMetadata(string $key, int $size): void
    {
        $metaKey = self::METADATA_PREFIX . $key;
        apcu_store($metaKey, ['size' => $size, 'created' => time()], 3600);
    }

    private function deleteMetadata(string $key): void
    {
        apcu_delete(self::METADATA_PREFIX . $key);
    }

    private function performMaintenance(): void
    {
        $memoryInfo = $this->getMemoryInfo();
        if ($this->memoryWarningIssued && $memoryInfo['usage_percent'] < (self::MEMORY_WARNING_THRESHOLD * 100 * 0.8)) {
            $this->memoryWarningIssued = false;
            $this->logger->info('APCu memory usage is back to normal.', (array) $memoryInfo);
        }
    }

    /** @param list<string> $prefixes */
    private function clearByPrefix(array $prefixes): int
    {
        $deletedCount = 0;
        foreach ($prefixes as $prefix) {
            $pattern = '#^' . preg_quote($prefix) . '#';
            $iterator = new \APCUIterator($pattern, \APC_ITER_KEY);
            $keysToDelete = [];
            foreach ($iterator as $item) {
                if (isset($item['key']) && is_string($item['key'])) {
                    $keysToDelete[] = $item['key'];
                }
            }
            if (!empty($keysToDelete)) {
                apcu_delete($keysToDelete);
                $deletedCount += count($keysToDelete);
            }
        }

        return $deletedCount;
    }

    private function removeFromAllTags(string $fullKey): void
    {
        $iterator = new \APCUIterator('#^' . preg_quote(self::TAG_INDEX_PREFIX) . '#', \APC_ITER_KEY | \APC_ITER_VALUE);
        foreach ($iterator as $tagItem) {
            if (!isset($tagItem['key']) || !is_array($tagItem['value'])) {
                continue;
            }
            $keysInIndex = $tagItem['value'];
            $index = array_search($fullKey, $keysInIndex, true);
            if (false !== $index) {
                unset($keysInIndex[$index]);
                if (empty($keysInIndex)) {
                    apcu_delete($tagItem['key']);
                } else {
                    apcu_store($tagItem['key'], array_values($keysInIndex));
                }
            }
        }
    }

    private function invalidateByPrefix(string $indexPrefix, array $names): bool
    {
        if (empty($names)) {
            return true;
        }
        $indexKeys = array_map(fn ($name) => $indexPrefix . $name, $names);
        $keysFromIndexes = apcu_fetch($indexKeys);

        if (!is_array($keysFromIndexes)) {
            return true;
        }

        $itemKeysToDelete = [];
        foreach ($keysFromIndexes as $keys) {
            if (is_array($keys)) {
                $itemKeysToDelete = array_merge($itemKeysToDelete, $keys);
            }
        }

        if (!empty($itemKeysToDelete)) {
            apcu_delete(array_unique($itemKeysToDelete));
        }
        apcu_delete($indexKeys);

        return true;
    }

    private function logInitialization(): void
    {
        $this->logger->info('APCu driver initialized', [
            'prefix' => $this->keyPrefix,
            'memory_info' => $this->getMemoryInfo(),
        ]);
    }
}
