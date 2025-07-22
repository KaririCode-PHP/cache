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
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationCalculatorTrait;
use KaririCode\Cache\Validation\Trait\TagValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlCalculatorTrait;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An enterprise-grade Memcached driver with comprehensive error handling,
 * native atomic operations, and health-check monitoring.
 */
final class MemcachedDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TtlCalculatorTrait;
    use ExpirationCalculatorTrait;

    private \Memcached $memcached;
    private string $keyPrefix;
    private LoggerInterface $logger;
    private int $operationCount = 0;

    private const HEALTH_CHECK_INTERVAL = 100;

    public function __construct(\Memcached $memcached, string $keyPrefix = 'cache:', ?LoggerInterface $logger = null)
    {
        $this->memcached = $memcached;
        $this->keyPrefix = $keyPrefix;
        $this->logger = $logger ?? new NullLogger();

        $this->verifyConnection();
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $this->incrementOperations();
        try {
            $value = $this->memcached->get($this->keyPrefix . $key);
            if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
                return null;
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
        try {
            $ttl = $this->calculateTtlForMemcached($expiration);
            $result = $this->memcached->set($this->keyPrefix . $key, $value, $ttl);
            if (!$result) {
                $this->handleMemcachedError('set', $key, $this->memcached->getResultCode());
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
        $this->memcached->get($this->keyPrefix . $key);

        return \Memcached::RES_SUCCESS === $this->memcached->getResultCode();
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();
        try {
            $result = $this->memcached->delete($this->keyPrefix . $key);
            if (!$result && \Memcached::RES_NOTFOUND !== $this->memcached->getResultCode()) {
                $this->handleMemcachedError('delete', $key, $this->memcached->getResultCode());

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->handleError('delete', $key, $e);

            return false;
        }
    }

    public function clear(): bool
    {
        $this->incrementOperations();
        try {
            return $this->memcached->flush();
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
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $rawValues = $this->memcached->getMulti($prefixedKeys);
        if (!is_array($rawValues)) {
            return array_fill_keys($keys, null);
        }
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $rawValues[$this->keyPrefix . $key] ?? null;
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
            $prefixedValues[$this->keyPrefix . (string) $key] = $value;
        }
        $ttl = $this->calculateTtlForMemcached($expiration);

        return $this->memcached->setMulti($prefixedValues, $ttl);
    }

    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);
        if (empty($keys)) {
            return true;
        }
        $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $keys);
        $results = $this->memcached->deleteMulti($prefixedKeys);

        return is_array($results) && !in_array(false, array_values($results), true);
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;
        $ttlValue = $this->calculateTtl($ttl);

        $result = $this->memcached->increment($fullKey, $step, $initialValue, $ttlValue);

        if (false === $result) {
            $this->handleMemcachedError('increment', $key, $this->memcached->getResultCode());
            throw CacheException::operationFailed('increment', $key);
        }

        return $result;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();
        $ttlValue = $this->calculateTtl($ttl);

        return $this->memcached->add($this->keyPrefix . $key, $value, $ttlValue);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $casToken = 0.0; // Will be populated by reference
        $currentValue = $this->memcached->get($this->keyPrefix . $key, null, $casToken);

        if (\Memcached::RES_SUCCESS !== $this->memcached->getResultCode()) {
            return false;
        }

        if ($currentValue !== $expected) {
            return false;
        }

        $ttlValue = $this->calculateTtlForMemcached($this->calculateExpiration($ttl));

        return $this->memcached->cas($casToken, $this->keyPrefix . $key, $value, $ttlValue);
    }

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->logger->warning('Memcached driver does not support native tagging. Tags will be ignored.', [
            'key' => $key,
            'tags' => array_map('strval', $tags),
        ]);

        return $this->set($key, $value, $this->calculateExpiration($ttl));
    }

    public function invalidateTags(array $tags): bool
    {
        $this->logger->warning('Tag invalidation is not natively supported by Memcached.', ['tags' => array_map('strval', $tags)]);

        return true;
    }

    public function getByTags(array $tags): array
    {
        $this->logger->warning('getByTags is not natively supported by Memcached.', ['tags' => $tags]);

        return [];
    }

    public function getTags(): array
    {
        $this->logger->warning('getTags is not natively supported by Memcached.');

        return [];
    }

    public function getCapabilities(): array
    {
        return [CacheCapabilities::ATOMIC_OPERATIONS];
    }

    public function getStats(): DriverStats
    {
        try {
            $stats = $this->memcached->getStats() ?: [];
            $version = $this->memcached->getVersion();
            $firstServerKey = (string) (key($stats) ?? '');
            $aggregated = $firstServerKey && isset($stats[$firstServerKey]) ? $stats[$firstServerKey] : [];

            return new MetricsSnapshot(
                'Memcached',
                isset($aggregated['bytes']) ? (int) $aggregated['bytes'] : null,
                isset($aggregated['curr_items']) ? (int) $aggregated['curr_items'] : null,
                is_array($version) ? (string) reset($version) : null,
                $stats
            );
        } catch (\Throwable $e) {
            return new MetricsSnapshot('Memcached', null, null, null, ['error' => $e->getMessage()]);
        }
    }

    private function verifyConnection(): void
    {
        if (empty($this->memcached->getServerList())) {
            throw ConfigurationException::invalidConfiguration('memcached', 'No servers are configured.');
        }
        if (empty($this->memcached->getVersion())) {
            throw CacheException::connectionFailed('memcached');
        }
    }

    private function incrementOperations(): void
    {
        if (0 === ++$this->operationCount % self::HEALTH_CHECK_INTERVAL) {
            $this->performHealthCheck();
        }
    }

    private function performHealthCheck(): void
    {
        try {
            if (empty($this->memcached->getStats())) {
                $this->handleError('healthCheck', 'n/a', new \Exception('Stats returned empty.'));
            }
        } catch (\Throwable $e) {
            $this->handleError('healthCheck', 'n/a', $e);
        }
    }

    private function handleMemcachedError(string $operation, string $key, int $resultCode): void
    {
        if (\Memcached::RES_SUCCESS === $resultCode || \Memcached::RES_NOTFOUND === $resultCode) {
            return;
        }
        $errorMessage = $this->memcached->getResultMessage();
        $this->logger->error('Memcached operation failed', [
            'operation' => $operation, 'key' => $key, 'result_code' => $resultCode, 'error_message' => $errorMessage,
        ]);
    }

    private function handleError(string $operation, string $key, \Throwable $e): void
    {
        $this->logger->error('Memcached operation error', [
            'operation' => $operation, 'key' => $key, 'error' => $e->getMessage(),
        ]);
    }

    private function calculateTtlForMemcached(?\DateTimeInterface $expiration): int
    {
        if (null === $expiration) {
            return 0;
        }

        $ttl = $expiration->getTimestamp() - time();

        if ($ttl > 2592000) { // 30 days
            return $expiration->getTimestamp();
        }

        return $ttl > 0 ? $ttl : 0;
    }
}
