<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Specialized\Trait\PrefixSanitizerTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlCalculatorTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ApcuDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use SerializationTrait;
    use TtlCalculatorTrait;
    use PrefixSanitizerTrait;

    private string $keyPrefix;
    private LoggerInterface $logger;
    private bool $memoryWarningIssued = false;
    private int $operationCount = 0;

    // Constantes específicas da implementação do ApcuDriver
    private const TAG_INDEX_PREFIX = 'tag_index::';
    private const LOCK_PREFIX = 'lock::';
    private const METADATA_PREFIX = 'meta::';
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

        if (!is_array($result)) {
            return false;
        }

        return empty($result);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|\DateTimeInterface|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

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
            return false;
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
        $this->validateTagStrings($tags);
        $this->incrementOperations();
        if (empty($tags)) {
            return true;
        }

        return $this->invalidateByPrefix(self::TAG_INDEX_PREFIX, $tags);
    }

    public function getTags(): array
    {
        $tags = [];
        $iterator = new \APCUIterator('#^' . preg_quote(self::TAG_INDEX_PREFIX) . '#', \APC_ITER_KEY);
        foreach ($iterator as $item) {
            if (is_array($item) && isset($item['key']) && is_string($item['key'])) {
                $tags[] = substr($item['key'], strlen(self::TAG_INDEX_PREFIX));
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
        $phpVersion = phpversion('apcu');

        $memSize = isset($info['mem_size']) && is_numeric($info['mem_size']) ? (int) $info['mem_size'] : null;
        $numEntries = isset($info['num_entries']) && is_numeric($info['num_entries']) ? (int) $info['num_entries'] : null;

        return new MetricsSnapshot(
            'Apcu',
            $memSize,
            $numEntries,
            $phpVersion ?: null,
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
            $this->logger->warning('APCu memory usage high', $memoryInfo);
            $this->memoryWarningIssued = true;
        }
    }

    /**
     * @return array{total_memory: int, available_memory: int, used_memory: int, usage_percent: float}
     */
    private function getMemoryInfo(): array
    {
        $smaInfo = apcu_sma_info() ?: [];
        $totalMemory = isset($smaInfo['seg_size']) ? (int) $smaInfo['seg_size'] : 1;
        $availableMemory = isset($smaInfo['avail_mem']) ? (int) $smaInfo['avail_mem'] : 0;
        $usedMemory = $totalMemory - $availableMemory;

        return [
            'total_memory' => $totalMemory,
            'available_memory' => $availableMemory,
            'used_memory' => $usedMemory,
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
            $this->logger->info('APCu memory usage is back to normal.', $memoryInfo);
        }
    }

    /** @param string[] $prefixes */
    private function clearByPrefix(array $prefixes): int
    {
        $deletedCount = 0;
        foreach ($prefixes as $prefix) {
            $pattern = '#^' . preg_quote($prefix) . '#';
            $iterator = new \APCUIterator($pattern, \APC_ITER_KEY);
            $keysToDelete = [];
            foreach ($iterator as $item) {
                if (is_array($item) && isset($item['key']) && is_string($item['key'])) {
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
            if (!is_array($tagItem) || !isset($tagItem['key'], $tagItem['value']) || !is_array($tagItem['value'])) {
                continue;
            }

            $keysInIndex = $tagItem['value'];
            $index = array_search($fullKey, $keysInIndex, true);

            if (false !== $index) {
                unset($keysInIndex[$index]);
                if (empty($keysInIndex)) {
                    apcu_delete((string) $tagItem['key']);
                } else {
                    apcu_store((string) $tagItem['key'], array_values($keysInIndex));
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
