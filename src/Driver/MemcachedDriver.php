<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats as DriverStatsContract;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Driver\Concern\DriverValidationTrait;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise-grade Memcached driver with comprehensive error handling and monitoring.
 */
final class MemcachedDriver implements
    CacheDriver,
    AtomicCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    use DriverValidationTrait;

    private \Memcached $memcached;
    private string $keyPrefix;
    private LoggerInterface $logger;
    private bool $connected = false;
    private int $operationCount = 0;

    // Security and performance limits
    private const HEALTH_CHECK_INTERVAL = 100;

    public function __construct(\Memcached $memcached, string $keyPrefix = 'cache:', ?LoggerInterface $logger = null)
    {
        $this->memcached = $memcached;
        $this->keyPrefix = $this->sanitizePrefix($keyPrefix);
        $this->logger = $logger ?? new NullLogger();

        $this->verifyConnection();
        $this->logInitialization();
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $this->incrementOperations();

        try {
            $value = $this->memcached->get($this->keyPrefix . $key);
            $resultCode = $this->memcached->getResultCode();

            if (\Memcached::RES_NOTFOUND === $resultCode) {
                return null;
            }

            if (\Memcached::RES_SUCCESS !== $resultCode) {
                $this->handleMemcachedError('get', $key, $resultCode);

                return null;
            }

            $this->logger->debug('Memcached get successful', [
                'key' => $key,
                'size' => is_string($value) ? strlen($value) : null,
            ]);

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
            $ttl = $this->calculateTtl($expiration);
            $result = $this->memcached->set($this->keyPrefix . $key, $value, $ttl);

            if (!$result) {
                $this->handleMemcachedError('set', $key, $this->memcached->getResultCode());

                return false;
            }

            $this->logger->debug('Memcached set successful', [
                'key' => $key,
                'size' => strlen(serialize($value)),
                'ttl' => $ttl,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->handleError('set', $key, $e);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $this->incrementOperations();

        try {
            $this->memcached->get($this->keyPrefix . $key);
            $resultCode = $this->memcached->getResultCode();

            return \Memcached::RES_SUCCESS === $resultCode;
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
            $result = $this->memcached->delete($this->keyPrefix . $key);
            $resultCode = $this->memcached->getResultCode();

            if (\Memcached::RES_NOTFOUND === $resultCode) {
                return true;
            }

            if (!$result) {
                $this->handleMemcachedError('delete', $key, $resultCode);

                return false;
            }

            $this->logger->debug('Memcached delete successful', ['key' => $key]);

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
            $result = $this->memcached->flush();

            if (!$result) {
                $this->handleMemcachedError('clear', 'all', $this->memcached->getResultCode());

                return false;
            }

            $this->logger->info('Memcached cache cleared');

            return true;
        } catch (\Throwable $e) {
            $this->handleError('clear', 'all', $e);

            return false;
        }
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

        try {
            $results = [];
            $batches = array_chunk($keys, self::MAX_BATCH_SIZE);

            foreach ($batches as $batch) {
                $prefixedKeys = array_map(fn ($k) => $this->keyPrefix . $k, $batch);
                $batchResults = $this->memcached->getMulti($prefixedKeys);

                if (false === $batchResults) {
                    $this->handleMemcachedError('getMultiple', implode(',', $batch), $this->memcached->getResultCode());
                    foreach ($batch as $key) {
                        $results[$key] = null;
                    }
                    continue;
                }

                foreach ($batch as $key) {
                    $prefixedKey = $this->keyPrefix . $key;
                    $results[$key] = $batchResults[$prefixedKey] ?? null;
                }
            }

            $this->logger->debug('Memcached getMultiple completed', [
                'requested_count' => count($keys),
                'found_count' => count(array_filter($results, fn ($v) => null !== $v)),
            ]);

            return $results;
        } catch (\Throwable $e) {
            $this->handleError('getMultiple', implode(',', $keys), $e);

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
            $prefixedValues = [];
            $totalSize = 0;

            foreach ($values as $key => $value) {
                $prefixedValues[$this->keyPrefix . $key] = $value;
                $totalSize += strlen(serialize($value));
            }

            $ttl = $this->calculateTtl($expiration);
            $result = $this->memcached->setMulti($prefixedValues, $ttl);

            if (!$result) {
                $this->handleMemcachedError('setMultiple', 'batch', $this->memcached->getResultCode());

                return false;
            }

            $this->logger->debug('Memcached setMultiple completed', [
                'count' => count($values),
                'total_size' => $totalSize,
                'ttl' => $ttl,
            ]);

            return true;
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
            $success = true;
            $deletedCount = 0;

            foreach ($prefixedKeys as $prefixedKey) {
                $result = $this->memcached->delete($prefixedKey);
                $resultCode = $this->memcached->getResultCode();

                if ($result || \Memcached::RES_NOTFOUND === $resultCode) {
                    ++$deletedCount;
                } else {
                    $success = false;
                    $this->logger->warning('Memcached delete failed', [
                        'key' => $prefixedKey,
                        'result_code' => $resultCode,
                    ]);
                }
            }

            $this->logger->debug('Memcached deleteMultiple completed', [
                'requested_count' => count($keys),
                'deleted_count' => $deletedCount,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            $this->handleError('deleteMultiple', implode(',', $keys), $e);

            return false;
        }
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);
        $this->incrementOperations();

        $fullKey = $this->keyPrefix . $key;
        $ttlValue = $this->calculateTtl($ttl);

        try {
            $result = ($step > 0) ? $this->memcached->increment($fullKey, $step) : $this->memcached->decrement($fullKey, abs($step));

            if (false === $result) {
                $resultCode = $this->memcached->getResultCode();
                if (\Memcached::RES_NOTFOUND === $resultCode) {
                    $newValue = $initialValue + $step;
                    if ($this->memcached->add($fullKey, $newValue, $ttlValue)) {
                        $this->logger->debug('Memcached increment initialized', ['key' => $key, 'result' => $newValue]);

                        return $newValue;
                    }
                    $result = ($step > 0) ? $this->memcached->increment($fullKey, $step) : $this->memcached->decrement($fullKey, abs($step));
                }

                if (false === $result) {
                    $this->handleMemcachedError('increment', $key, $this->memcached->getResultCode());
                    throw CacheException::operationFailed('increment', $key);
                }
            }

            $this->logger->debug('Memcached increment successful', ['key' => $key, 'step' => $step, 'result' => $result]);

            return (int) $result;
        } catch (\Throwable $e) {
            $this->handleError('increment', $key, $e);
            throw CacheException::operationFailed('increment', $key, $e);
        }
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->incrementOperations();

        try {
            $ttlValue = $this->calculateTtl($ttl);
            $result = $this->memcached->add($this->keyPrefix . $key, $value, $ttlValue);

            if (!$result) {
                $resultCode = $this->memcached->getResultCode();
                if (\Memcached::RES_NOTSTORED === $resultCode) {
                    return false; // Key already exists
                }
                $this->handleMemcachedError('add', $key, $resultCode);

                return false;
            }

            $this->logger->debug('Memcached add successful', ['key' => $key]);

            return true;
        } catch (\Throwable $e) {
            $this->handleError('add', $key, $e);

            return false;
        }
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);

        // A forma correta de obter o token CAS é com a flag GET_EXTENDED
        $result = $this->memcached->get($this->keyPrefix . $key, null, \Memcached::GET_EXTENDED);

        // Se o get falhar ou a chave não existir, a operação falha.
        if (!is_array($result) || !isset($result['cas']) || !isset($result['value'])) {
            return false;
        }

        // Compara o valor atual com o esperado
        if ($result['value'] === $expected) {
            $ttlValue = $this->calculateTtl($ttl);

            // Usa o token CAS obtido para fazer a troca atômica
            return $this->memcached->cas($result['cas'], $this->keyPrefix . $key, $value, $ttlValue);
        }

        return false;
    }

    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);
        $this->logger->warning('Tag invalidation is not natively supported by Memcached.', ['tags' => $tags]);

        return true;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        $this->logger->warning('getTags is not natively supported by Memcached.');

        return [];
    }

    /**
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
        ];
    }

    public function getStats(): DriverStatsContract
    {
        try {
            $stats = $this->memcached->getStats() ?: [];
            /** @var array<string, string>|false $version */
            $version = $this->memcached->getVersion();
            $aggregated = $this->aggregateServerStats($stats);

            return new DriverStats(
                driverName: 'Memcached',
                memoryUsage: $aggregated['total_bytes'], // O erro aqui será resolvido pelo PHPDoc abaixo
                itemCount: $aggregated['total_items'],   // O erro aqui será resolvido pelo PHPDoc abaixo
                driverVersion: is_array($version) ? (string) reset($version) : null,
                rawStats: $stats
            );
        } catch (\Throwable $e) {
            return new DriverStats('Memcached', null, null, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{status: string, write_success?: bool, read_success?: bool, delete_success?: bool, latency_ms?: float, server_count?: int, healthy_servers?: int, memcached_version?: string|null, error?: string, connected?: bool}
     */
    public function healthCheck(): array
    {
        try {
            $testKey = $this->keyPrefix . 'health_check_' . uniqid();
            $testValue = 'health_check_value';
            $start = microtime(true);

            $writeResult = $this->memcached->set($testKey, $testValue, 10);
            $readResult = $this->memcached->get($testKey);
            $deleteResult = $this->memcached->delete($testKey);
            $latency = microtime(true) - $start;
            $stats = $this->memcached->getStats() ?: [];

            return [
                'status' => 'healthy',
                'write_success' => $writeResult,
                'read_success' => $readResult === $testValue,
                'delete_success' => $deleteResult,
                'latency_ms' => round($latency * 1000, 2),
                'server_count' => count($this->memcached->getServerList()),
                'healthy_servers' => $this->countHealthyServers($stats),
                'memcached_version' => $this->getAnyVersion(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connected' => $this->connected,
            ];
        }
    }

    private function sanitizePrefix(string $prefix): string
    {
        $sanitized = (string) preg_replace('/[^\w\-\.\:]/', '_', $prefix);

        return substr($sanitized, 0, 50);
    }

    private function calculateTtl(\DateTimeInterface|int|\DateInterval|null $ttl): int
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

    private function verifyConnection(): void
    {
        try {
            if (empty($this->memcached->getServerList())) {
                throw ConfigurationException::invalidConfiguration('memcached', 'No servers are configured.');
            }
            if (empty($this->memcached->getVersion())) {
                throw CacheException::connectionFailed('memcached');
            }

            $this->connected = true;
        } catch (\Throwable $e) {
            $this->connected = false;
            $this->logger->error('Memcached connection verification failed', ['error' => $e->getMessage()]);
            throw CacheException::connectionFailed('memcached', $e);
        }
    }

    private function handleMemcachedError(string $operation, string $key, int $resultCode): void
    {
        $errorMessage = $this->memcached->getResultMessage();
        $this->logger->error('Memcached operation failed', [
            'operation' => $operation, 'key' => $key, 'result_code' => $resultCode, 'error_message' => $errorMessage,
        ]);
        if (in_array($resultCode, [\Memcached::RES_HOST_LOOKUP_FAILURE, \Memcached::RES_SERVER_ERROR, \Memcached::RES_TIMEOUT])) {
            $this->connected = false;
        }
    }

    private function handleError(string $operation, string $key, \Throwable $e): void
    {
        $this->connected = false;
        $this->logger->error('Memcached operation error', [
            'operation' => $operation, 'key' => $key, 'error' => $e->getMessage(),
        ]);
    }

    /**
     * @param array<string, array<string, int|string>> $stats
     *
     * @return array{
     * total_items: int,
     * total_connections: int,
     * total_get_hits: int,
     * total_get_misses: int,
     * total_cmd_get: int,
     * total_cmd_set: int,
     * total_bytes: int,
     * total_bytes_read: int,
     * total_bytes_written: int,
     * uptime_min: int|null,
     * hit_rate: float
     * }
     */
    private function aggregateServerStats(array $stats): array
    {
        $aggregated = [
            'total_items' => 0, 'total_connections' => 0, 'total_get_hits' => 0, 'total_get_misses' => 0,
            'total_cmd_get' => 0, 'total_cmd_set' => 0, 'total_bytes' => 0, 'total_bytes_read' => 0,
            'total_bytes_written' => 0, 'uptime_min' => null, 'hit_rate' => 0.0,
        ];

        foreach ($stats as $serverStats) {
            if (is_array($serverStats)) {
                $aggregated['total_items'] += (int) ($serverStats['curr_items'] ?? 0);
                $aggregated['total_connections'] += (int) ($serverStats['curr_connections'] ?? 0);
                $aggregated['total_get_hits'] += (int) ($serverStats['get_hits'] ?? 0);
                $aggregated['total_get_misses'] += (int) ($serverStats['get_misses'] ?? 0);
                $aggregated['total_cmd_get'] += (int) ($serverStats['cmd_get'] ?? 0);
                $aggregated['total_cmd_set'] += (int) ($serverStats['cmd_set'] ?? 0);
                $aggregated['total_bytes'] += (int) ($serverStats['bytes'] ?? 0);
                $aggregated['total_bytes_read'] += (int) ($serverStats['bytes_read'] ?? 0);
                $aggregated['total_bytes_written'] += (int) ($serverStats['bytes_written'] ?? 0);
                $uptime = (int) ($serverStats['uptime'] ?? 0);
                $aggregated['uptime_min'] = is_null($aggregated['uptime_min']) ? $uptime : min($aggregated['uptime_min'], $uptime);
            }
        }

        $totalRequests = $aggregated['total_get_hits'] + $aggregated['total_get_misses'];
        $aggregated['hit_rate'] = $totalRequests > 0 ? round(($aggregated['total_get_hits'] / $totalRequests) * 100, 2) : 0.0;

        return $aggregated;
    }

    private function countHealthyServers(array $stats): int
    {
        $healthy = 0;
        foreach ($stats as $serverStats) {
            if (is_array($serverStats) && isset($serverStats['pid']) && $serverStats['pid'] > 0) {
                ++$healthy;
            }
        }

        return $healthy;
    }

    private function getAnyVersion(): ?string
    {
        try {
            $versions = $this->memcached->getVersion();
            if (!$versions) {
                return null;
            }

            return (string) reset($versions);
        } catch (\Throwable) {
            return null;
        }
    }

    private function incrementOperations(): void
    {
        ++$this->operationCount;
        if (0 === $this->operationCount % self::HEALTH_CHECK_INTERVAL) {
            $this->performHealthCheck();
        }
    }

    private function performHealthCheck(): void
    {
        try {
            $stats = $this->memcached->getStats();
            $this->connected = !empty($stats);
        } catch (\Throwable $e) {
            $this->connected = false;
            $this->logger->warning('Memcached health check failed', ['error' => $e->getMessage()]);
        }
    }

    private function logInitialization(): void
    {
        $this->logger->info('Memcached driver initialized', [
            'prefix' => $this->keyPrefix,
            'server_count' => count($this->memcached->getServerList()),
            'connected' => $this->connected,
        ]);
    }
}
