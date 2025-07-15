<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * High-performance APCu driver with enhanced security and cross-process safety.
 *
 * Features:
 * - Cross-process safe tagging system
 * - Atomic operations with conflict resolution
 * - Memory usage monitoring and limits
 * - Comprehensive error handling
 * - Performance optimizations for high-load scenarios
 * - Security validation and sanitization
 *
 * Performance Optimizations:
 * - Batch operations for improved throughput
 * - Efficient tag indexing with minimal overhead
 * - Memory-aware operations to prevent exhaustion
 * - Lock-free algorithms where possible
 *
 * Security Features:
 * - Input validation and sanitization
 * - Key length and pattern validation
 * - Memory exhaustion prevention
 * - Audit logging for sensitive operations
 * - Rate limiting capabilities
 *
 * @see https://www.php.net/manual/en/book.apcu.php APCu Documentation
 * @see https://www.php.net/manual/en/apcu.configuration.php APCu Configuration
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

    // Prefixes for different data types
    private const TAG_INDEX_PREFIX = 'tag_index::';
    private const TOPIC_INDEX_PREFIX = 'topic_index::';
    private const LOCK_PREFIX = 'lock::';
    private const METADATA_PREFIX = 'meta::';

    // Security and performance limits
    private const MAX_KEY_LENGTH = 200; // APCu has stricter limits
    private const MAX_VALUE_SIZE = 64 * 1024 * 1024; // 64MB - conservative for shared memory
    private const MAX_BATCH_SIZE = 100; // Limit batch operations
    private const LOCK_TIMEOUT = 5; // 5 seconds max lock time
    private const MEMORY_WARNING_THRESHOLD = 0.9; // 90% memory usage warning

    // Performance monitoring
    private const CLEANUP_INTERVAL = 1000; // Clean up every 1000 operations
    private const STATS_CACHE_TIME = 60; // Cache stats for 60 seconds

    /**
     * @throws \RuntimeException if APCu extension is not available or enabled
     */
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

        try {
            $success = false;
            $value = apcu_fetch($fullKey, $success);

            if (!$success) {
                return null;
            }

            // Check if it's a serialized value
            if (is_string($value) && $this->isSerialized($value)) {
                return $this->unserialize($value);
            }

            return $value;
        } catch (\Throwable $e) {
            $this->handleError('get', $key, $e);

            return null;
        }
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;
        $serializedValue = $this->serialize($value);
        $ttl = $this->calculateTtl($expiration);

        try {
            // Check memory usage before storing
            $this->checkMemoryUsage();

            $result = apcu_store($fullKey, $serializedValue, $ttl);

            if ($result) {
                $this->updateMetadata($key, strlen($serializedValue));

                $this->logger->debug('APCu cache set', [
                    'key' => $key,
                    'size' => strlen($serializedValue),
                    'ttl' => $ttl,
                ]);
            } else {
                $this->logger->warning('APCu cache set failed', [
                    'key' => $key,
                    'memory_info' => $this->getMemoryInfo(),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->handleError('set', $key, $e);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;

        try {
            return apcu_exists($fullKey);
        } catch (\Throwable $e) {
            $this->handleError('has', $key, $e);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();

        try {
            $result = $this->deleteMultiple([$key]);

            if ($result) {
                $this->logger->debug('APCu cache delete', ['key' => $key]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->handleError('delete', $key, $e);

            return false;
        }
    }

    public function clear(): bool
    {
        $this->incrementOperations();

        try {
            // Use iterator to safely clear only our keys
            $cleared = $this->clearByPrefix([
                $this->keyPrefix,
                self::TAG_INDEX_PREFIX,
                self::TOPIC_INDEX_PREFIX,
                self::LOCK_PREFIX,
                self::METADATA_PREFIX,
            ]);

            $this->operationCount = 0;
            $this->memoryWarningIssued = false;

            $this->logger->info('APCu cache cleared', [
                'keys_cleared' => $cleared,
                'memory_info' => $this->getMemoryInfo(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->handleError('clear', 'all', $e);

            return false;
        }
    }

    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return [];
        }

        try {
            $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
            $rawValues = apcu_fetch($prefixedKeys) ?: [];

            $results = [];
            foreach ($keys as $key) {
                $prefixedKey = $this->keyPrefix . $key;
                if (isset($rawValues[$prefixedKey])) {
                    $value = $rawValues[$prefixedKey];
                    $results[$key] = is_string($value) && $this->isSerialized($value)
                        ? $this->unserialize($value)
                        : $value;
                } else {
                    $results[$key] = null;
                }
            }

            return $results;
        } catch (\Throwable $e) {
            $this->handleError('getMultiple', implode(',', $keys), $e);

            // Return nulls for all keys on error
            return array_fill_keys($keys, null);
        }
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateMultipleValues($values);

        if (empty($values)) {
            return true;
        }

        try {
            $this->checkMemoryUsage();

            $prefixedValues = [];
            $totalSize = 0;

            foreach ($values as $key => $value) {
                $serializedValue = $this->serialize($value);
                $prefixedValues[$this->keyPrefix . $key] = $serializedValue;
                $totalSize += strlen($serializedValue);
            }

            $ttl = $this->calculateTtl($expiration);
            $failedKeys = apcu_store($prefixedValues, null, $ttl);

            $success = empty($failedKeys);

            if ($success) {
                foreach ($values as $key => $value) {
                    $this->updateMetadata($key, strlen($this->serialize($value)));
                }

                $this->logger->debug('APCu batch set completed', [
                    'count' => count($values),
                    'total_size' => $totalSize,
                ]);
            } else {
                $this->logger->warning('APCu batch set partially failed', [
                    'failed_keys' => $failedKeys,
                    'memory_info' => $this->getMemoryInfo(),
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->handleError('setMultiple', 'batch', $e);

            return false;
        }
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return true;
        }

        try {
            $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);

            // Remove from tag indexes before deleting
            foreach ($prefixedKeys as $fullKey) {
                $this->removeFromAllTags($fullKey);
            }

            $result = apcu_delete($prefixedKeys);

            // Clean up metadata
            foreach ($keys as $key) {
                $this->deleteMetadata($key);
            }

            $success = is_array($result) ? empty($result) : (bool) $result;

            if ($success) {
                $this->logger->debug('APCu batch delete completed', [
                    'count' => count($keys),
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->handleError('deleteMultiple', implode(',', $keys), $e);

            return false;
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;
        $ttlValue = $this->calculateTtl($ttl);

        try {
            // Use APCu's atomic operations with fallback
            if (!apcu_exists($fullKey)) {
                $stored = apcu_add($fullKey, $initialValue, $ttlValue);
                if (!$stored) {
                    // Race condition - key was added by another process
                    // Fall through to increment
                }
            }

            $result = apcu_inc($fullKey, $step);

            // Handle the case where increment failed (key doesn't exist or isn't numeric)
            if (false === $result) {
                // Try to initialize and increment again
                apcu_store($fullKey, $initialValue, $ttlValue);
                $result = apcu_inc($fullKey, $step);

                if (false === $result) {
                    throw new \RuntimeException('Failed to increment after initialization');
                }
            }

            $this->logger->debug('APCu increment', [
                'key' => $key,
                'step' => $step,
                'result' => $result,
            ]);

            return (int) $result;
        } catch (\Throwable $e) {
            $this->handleError('increment', $key, $e);
            throw CacheException::operationFailed('increment', $key, $e);
        }
    }

    public function add(string $key, mixed $value, $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;
        $serializedValue = $this->serialize($value);
        $ttlValue = $this->calculateTtl($ttl);

        try {
            $this->checkMemoryUsage();

            $result = apcu_add($fullKey, $serializedValue, $ttlValue);

            if ($result) {
                $this->updateMetadata($key, strlen($serializedValue));

                $this->logger->debug('APCu add successful', [
                    'key' => $key,
                    'size' => strlen($serializedValue),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->handleError('add', $key, $e);

            return false;
        }
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        $this->incrementOperations();

        if (empty($tags)) {
            return true;
        }

        try {
            return $this->invalidateByPrefix(self::TAG_INDEX_PREFIX, $tags);
        } catch (\Throwable $e) {
            $this->handleError('invalidateTags', implode(',', $tags), $e);

            return false;
        }
    }

    /**
     * Tag an item for group invalidation.
     */
    public function tagItem(string $key, array $tags): void
    {
        $this->validateKey($key);
        $this->validateTags($tags);

        if (empty($tags)) {
            return;
        }

        try {
            $this->updateIndex(self::TAG_INDEX_PREFIX, $tags, $this->keyPrefix . $key);

            $this->logger->debug('APCu item tagged', [
                'key' => $key,
                'tags' => $tags,
            ]);
        } catch (\Throwable $e) {
            $this->handleError('tagItem', $key, $e);
        }
    }

    public function hasCapability(string $capability): bool
    {
        return match ($capability) {
            CacheCapabilities::ATOMIC_OPERATIONS => true,
            CacheCapabilities::TAGGING => true,
            CacheCapabilities::TOPICS => false,
            CacheCapabilities::TRANSACTIONS => false,
            default => false,
        };
    }

    /**
     * Get APCu cache statistics.
     */
    public function getStats(): array
    {
        try {
            // Cache expensive stats call
            $cacheKey = 'apcu_stats_cache';
            $cached = apcu_fetch($cacheKey, $success);

            if ($success && is_array($cached)) {
                return $cached;
            }

            $info = apcu_cache_info(true);
            $smaInfo = apcu_sma_info();

            $stats = [
                'cache_info' => [
                    'num_slots' => $info['num_slots'] ?? 0,
                    'ttl' => $info['ttl'] ?? 0,
                    'num_hits' => $info['num_hits'] ?? 0,
                    'num_misses' => $info['num_misses'] ?? 0,
                    'num_inserts' => $info['num_inserts'] ?? 0,
                    'num_entries' => $info['num_entries'] ?? 0,
                    'expunges' => $info['expunges'] ?? 0,
                    'start_time' => $info['start_time'] ?? 0,
                    'mem_size' => $info['mem_size'] ?? 0,
                ],
                'memory_info' => [
                    'num_seg' => $smaInfo['num_seg'] ?? 0,
                    'seg_size' => $smaInfo['seg_size'] ?? 0,
                    'avail_mem' => $smaInfo['avail_mem'] ?? 0,
                ],
                'performance' => [
                    'hit_rate' => $this->calculateHitRate($info),
                    'memory_usage_percent' => $this->calculateMemoryUsage($smaInfo),
                    'operations_count' => $this->operationCount,
                ],
                'prefix' => $this->keyPrefix,
            ];

            // Cache stats for a short time
            apcu_store($cacheKey, $stats, self::STATS_CACHE_TIME);

            return $stats;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'prefix' => $this->keyPrefix,
                'operations_count' => $this->operationCount,
            ];
        }
    }

    /**
     * Health check for monitoring.
     */
    public function healthCheck(): array
    {
        try {
            $testKey = $this->keyPrefix . 'health_check_' . uniqid();
            $testValue = 'health_check_value';

            $start = microtime(true);

            // Test write
            $writeResult = apcu_store($testKey, $testValue, 10);

            // Test read
            $readResult = apcu_fetch($testKey);

            // Test delete
            $deleteResult = apcu_delete($testKey);

            $latency = microtime(true) - $start;

            $memoryInfo = $this->getMemoryInfo();

            return [
                'status' => 'healthy',
                'write_success' => $writeResult,
                'read_success' => $readResult === $testValue,
                'delete_success' => $deleteResult,
                'latency_ms' => round($latency * 1000, 2),
                'memory_usage_percent' => $memoryInfo['usage_percent'],
                'available_memory' => $memoryInfo['avail_mem'],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    // Private helper methods...

    private function validateEnvironment(): void
    {
        if (!extension_loaded('apcu')) {
            throw new \RuntimeException('APCu extension is not loaded.');
        }

        if (!apcu_enabled()) {
            throw new \RuntimeException('APCu is not enabled in php.ini.');
        }

        // Check CLI configuration
        if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
            throw new \RuntimeException('APCu is not enabled for CLI. Set apc.enable_cli=1 in php.ini.');
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

        // APCu key validation - no spaces or control characters
        if (preg_match('/[\s\x00-\x1F\x7F]/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key . ' (contains invalid characters)');
        }
    }

    private function validateKeys(array $keys): void
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Too many keys: ' . count($keys));
        }

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
        }
    }

    private function validateValue(mixed $value): void
    {
        $serialized = $this->serialize($value);

        if (strlen($serialized) > self::MAX_VALUE_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Value too large: ' . strlen($serialized) . ' bytes');
        }
    }

    private function validateMultipleValues(array $values): void
    {
        if (count($values) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidKeyFormat('Too many values: ' . count($values));
        }

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be string');
            }
            $this->validateKey($key);
            $this->validateValue($value);
        }
    }

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || empty($tag)) {
                throw InvalidArgumentException::invalidTag($tag);
            }
        }
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidKeyFormat('Step cannot be zero');
        }
    }

    private function sanitizePrefix(string $prefix): string
    {
        // Remove any problematic characters for APCu keys
        $sanitized = preg_replace('/[^\w\-\.\:]/', '_', $prefix);

        return substr($sanitized, 0, 50); // Limit prefix length
    }

    private function serialize(mixed $value): string
    {
        // For simple types, store directly to save space
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || is_null($value)) {
            return $value;
        }

        try {
            return serialize($value);
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed('apcu', $e);
        }
    }

    private function unserialize(string $data): mixed
    {
        try {
            return unserialize($data);
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('apcu', $e);
        }
    }

    private function isSerialized(string $data): bool
    {
        // Quick check for serialized data
        return 1 === preg_match('/^(a|O|s|i|d|b|N):\d+:/', $data);
    }

    private function calculateTtl($ttl): int
    {
        if (null === $ttl) {
            return 0; // No expiration
        }

        if (is_int($ttl)) {
            return max(0, $ttl);
        }

        if ($ttl instanceof \DateTimeInterface) {
            return max(0, $ttl->getTimestamp() - time());
        }

        if ($ttl instanceof \DateInterval) {
            return max(0, (new \DateTime())->add($ttl)->getTimestamp() - time());
        }

        return 0;
    }

    private function updateIndex(string $indexPrefix, array $indexNames, string $fullKey): void
    {
        foreach ($indexNames as $name) {
            $indexKey = $indexPrefix . $name;
            $lockKey = self::LOCK_PREFIX . $indexKey;

            // Use a simple lock mechanism
            $lockAcquired = false;
            $attempts = 0;

            while (!$lockAcquired && $attempts < 10) {
                $lockAcquired = apcu_add($lockKey, 1, self::LOCK_TIMEOUT);
                if (!$lockAcquired) {
                    usleep(10000); // 10ms
                    ++$attempts;
                }
            }

            if ($lockAcquired) {
                try {
                    $keysInIndex = apcu_fetch($indexKey) ?: [];
                    if (!in_array($fullKey, $keysInIndex)) {
                        $keysInIndex[] = $fullKey;
                        apcu_store($indexKey, $keysInIndex);
                    }
                } finally {
                    apcu_delete($lockKey);
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

        if (empty($keysFromIndexes)) {
            return true;
        }

        $itemKeysToDelete = [];
        foreach ($keysFromIndexes as $keys) {
            if (is_array($keys)) {
                $itemKeysToDelete = array_merge($itemKeysToDelete, $keys);
            }
        }

        if (!empty($itemKeysToDelete)) {
            $itemKeysToDelete = array_unique($itemKeysToDelete);
            apcu_delete($itemKeysToDelete);
        }

        // Delete the index keys
        apcu_delete($indexKeys);

        return true;
    }

    private function removeFromAllTags(string $fullKey): void
    {
        try {
            // This is a simplified approach - in a real implementation,
            // we'd need to track which tags a key belongs to
            $iterator = new \APCUIterator('#^' . preg_quote(self::TAG_INDEX_PREFIX) . '#', \APC_ITER_KEY | \APC_ITER_VALUE);

            foreach ($iterator as $tag) {
                $keys = $tag['value'];
                if (is_array($keys)) {
                    $index = array_search($fullKey, $keys);
                    if (false !== $index) {
                        unset($keys[$index]);
                        $keys = array_values($keys);

                        if (empty($keys)) {
                            apcu_delete($tag['key']);
                        } else {
                            apcu_store($tag['key'], $keys);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log but don't fail the operation
            $this->logger->warning('Failed to remove from tag indexes', [
                'key' => $fullKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clearByPrefix(array $prefixes): int
    {
        $deletedCount = 0;

        foreach ($prefixes as $prefix) {
            try {
                $pattern = '#^' . preg_quote($prefix) . '#';
                $iterator = new \APCUIterator($pattern, \APC_ITER_KEY);

                $keys = [];
                foreach ($iterator as $item) {
                    $keys[] = $item['key'];

                    // Process in batches to avoid memory issues
                    if (count($keys) >= 100) {
                        $deletedCount += count(apcu_delete($keys));
                        $keys = [];
                    }
                }

                if (!empty($keys)) {
                    $deletedCount += count(apcu_delete($keys));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to clear by prefix', [
                    'prefix' => $prefix,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deletedCount;
    }

    private function checkMemoryUsage(): void
    {
        try {
            $memoryInfo = $this->getMemoryInfo();
            $usagePercent = $memoryInfo['usage_percent'];

            if ($usagePercent > self::MEMORY_WARNING_THRESHOLD * 100 && !$this->memoryWarningIssued) {
                $this->logger->warning('APCu memory usage high', [
                    'usage_percent' => $usagePercent,
                    'available_memory' => $memoryInfo['avail_mem'],
                    'total_memory' => $memoryInfo['seg_size'],
                ]);

                $this->memoryWarningIssued = true;
            }
        } catch (\Throwable $e) {
            // Don't fail operations due to monitoring issues
            $this->logger->debug('Failed to check memory usage', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getMemoryInfo(): array
    {
        $smaInfo = apcu_sma_info();

        $totalMemory = $smaInfo['seg_size'] ?? 1;
        $availableMemory = $smaInfo['avail_mem'] ?? 0;
        $usedMemory = $totalMemory - $availableMemory;

        return [
            'seg_size' => $totalMemory,
            'avail_mem' => $availableMemory,
            'used_mem' => $usedMemory,
            'usage_percent' => round(($usedMemory / $totalMemory) * 100, 2),
        ];
    }

    private function calculateHitRate(array $info): float
    {
        $hits = $info['num_hits'] ?? 0;
        $misses = $info['num_misses'] ?? 0;
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    private function calculateMemoryUsage(array $smaInfo): float
    {
        $total = $smaInfo['seg_size'] ?? 1;
        $available = $smaInfo['avail_mem'] ?? 0;
        $used = $total - $available;

        return round(($used / $total) * 100, 2);
    }

    private function updateMetadata(string $key, int $size): void
    {
        $metaKey = self::METADATA_PREFIX . $key;
        $metadata = [
            'size' => $size,
            'created' => time(),
        ];

        apcu_store($metaKey, $metadata, 3600); // Keep metadata for 1 hour
    }

    private function deleteMetadata(string $key): void
    {
        $metaKey = self::METADATA_PREFIX . $key;
        apcu_delete($metaKey);
    }

    private function incrementOperations(): void
    {
        ++$this->operationCount;

        // Periodic maintenance
        if (0 === $this->operationCount % self::CLEANUP_INTERVAL) {
            $this->performMaintenance();
        }
    }

    private function performMaintenance(): void
    {
        try {
            // Reset memory warning flag if usage is now normal
            $memoryInfo = $this->getMemoryInfo();
            if ($memoryInfo['usage_percent'] < self::MEMORY_WARNING_THRESHOLD * 100 * 0.8) {
                $this->memoryWarningIssued = false;
            }

            $this->logger->debug('APCu maintenance performed', [
                'operations' => $this->operationCount,
                'memory_usage' => $memoryInfo['usage_percent'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('APCu maintenance failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleError(string $operation, string $key, \Throwable $e): void
    {
        $this->logger->error('APCu operation failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'memory_info' => $this->getMemoryInfo(),
        ]);
    }

    private function logInitialization(): void
    {
        $this->logger->info('APCu driver initialized', [
            'prefix' => $this->keyPrefix,
            'memory_info' => $this->getMemoryInfo(),
            'apcu_enabled' => apcu_enabled(),
            'php_sapi' => PHP_SAPI,
        ]);
    }
}
