<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats as DriverStatsContract;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileSystemStatistics;
use KaririCode\Cache\Contract\Filesystem\FileSystemValidator;
use KaririCode\Cache\Driver\Filesystem\Cache\FileSystemCacheOperations;
use Psr\Log\LoggerInterface;

/**
 * Filesystem cache driver following SOLID principles.
 * Responsible ONLY for coordinating operations between specialized components.
 */
final class FilesystemDriver implements CacheDriver, Maintainable
{
    public function __construct(
        private DirectoryManager $directoryManager,
        private FileSystemCacheOperations $cacheOperations,
        private FileSystemStatistics $statistics,
        private FileSystemValidator $validator,
        private LoggerInterface $logger
    ) {
        $this->logger->info('FilesystemDriver initialized with SOLID architecture');
    }

    public function get(string $key): mixed
    {
        $startTime = microtime(true);
        try {
            $this->validator->validateKey($key);
            $result = $this->cacheOperations->get($key);
            $this->statistics->recordOperation('get', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get cache item', ['key' => $key, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $startTime = microtime(true);
        try {
            $this->validator->validateKey($key);
            $this->validator->validateValue($value);
            $result = $this->cacheOperations->set($key, $value, $expiration);
            $this->statistics->recordOperation('set', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set cache item', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $startTime = microtime(true);
        try {
            $this->validator->validateKey($key);
            $result = $this->cacheOperations->has($key);
            $this->statistics->recordOperation('has', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check cache item', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $startTime = microtime(true);
        try {
            $this->validator->validateKey($key);
            $result = $this->cacheOperations->delete($key);
            $this->statistics->recordOperation('delete', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete cache item', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function clear(): bool
    {
        $startTime = microtime(true);
        try {
            $result = $this->directoryManager->clear();
            $this->statistics->recordOperation('clear', microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear cache', ['error' => $e->getMessage()]);

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
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $expiration)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        // Filesystem driver does not support advanced atomic operations or tagging natively.
        return [];
    }

    public function getStats(): DriverStatsContract
    {
        $stats = $this->statistics->getStats();

        return new DriverStats(
            driverName: 'Filesystem',
            memoryUsage: $stats['directory_size'],
            itemCount: $stats['file_count'],
            driverVersion: phpversion(),
            rawStats: $stats
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return $this->statistics->healthCheck();
    }

    public function maintenance(array $options = []): bool
    {
        $startTime = microtime(true);
        $this->logger->info('Starting filesystem cache maintenance.');

        try {
            $this->cacheOperations->performCleanup();
            $duration = microtime(true) - $startTime;

            $this->logger->info('Filesystem cache maintenance completed successfully.', [
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Filesystem cache maintenance failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
