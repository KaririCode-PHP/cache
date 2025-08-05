<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileSystemStatistics;
use KaririCode\Cache\Contract\Filesystem\FileSystemValidator;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemCrudOperations;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemTagOperations;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Log\LoggerInterface;

/**
 * Enhanced filesystem-based cache driver with tag support.
 *
 * This driver provides filesystem-based caching with support for tag-based operations
 * while maintaining the existing filesystem architecture. It uses dedicated operation
 * classes following Clean Architecture principles.
 *
 * Key features:
 * - Implements TaggableCacheDriver interface
 * - Uses FilesystemCrudOperations for basic operations
 * - Delegates tag operations to FilesystemTagOperations
 * - Proper TTL management through ExpirationProvider
 * - Comprehensive error handling and logging
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FilesystemDriver implements
    CacheDriver,
    Maintainable,
    TaggableCacheDriver
{
    /**
     * Constructor applying Dependency Injection pattern.
     *
     * @param DirectoryManager $directoryManager Directory operations handler
     * @param FilesystemCrudOperations $crudOperations Basic CRUD operations handler
     * @param FilesystemTagOperations $tagOperations Tag-specific operations handler
     * @param FileSystemStatistics $statistics Statistics collection handler
     * @param FileSystemValidator $validator Filesystem validation handler
     * @param ExpirationProvider $expirationProvider Strategy for TTL management
     * @param LoggerInterface $logger PSR-3 logger for debugging and monitoring
     */
    public function __construct(
        private readonly DirectoryManager $directoryManager,
        private readonly FilesystemCrudOperations $crudOperations,
        private readonly FilesystemTagOperations $tagOperations,
        private readonly FileSystemStatistics $statistics,
        private readonly FileSystemValidator $validator,
        private readonly ExpirationProvider $expirationProvider,
        private readonly LoggerInterface $logger
    ) {
        $this->logger->info('Enhanced FilesystemDriver initialized with tag support');
    }

    // ========================================================================
    // Basic Cache Operations (CacheDriver interface)
    // ========================================================================

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->resolveExpiration($key, $ttl, 'set');

        return $this->crudOperations->set($key, $value, $expiration);
    }

    public function has(string $key): bool
    {
        return $this->crudOperations->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->crudOperations->delete($key);
    }

    public function clear(): bool
    {
        return $this->crudOperations->clear();
    }

    public function getMultiple(iterable $keys): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(iterable $values): bool
    {
        $success = true;
        $expiration = $this->resolveExpiration('', null, 'setMultiple');

        foreach ($values as $key => $value) {
            if (!$this->crudOperations->set($key, $value, $expiration)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    // ========================================================================
    // Tag Operations (TaggableCacheDriver interface)
    // ========================================================================

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        $expiration = $this->resolveExpiration($key, null, 'setWithTags');

        return $this->tagOperations->setWithTags($key, $value, $tags, $expiration);
    }

    public function getByTags(iterable $tags): iterable
    {
        return $this->tagOperations->getByTags($tags);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getTags(): iterable
    {
        return $this->tagOperations->getTags();
    }

    // ========================================================================
    // Driver Management and Statistics
    // ========================================================================

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::TAGGING,
        ];
    }

    public function getStats(): DriverStats
    {
        return $this->statistics;
    }

    public function maintenance(array $options = []): bool
    {
        try {
            // Cleanup expired entries
            $cleanupStats = $this->crudOperations->cleanupExpired();

            // Log cleanup results
            $this->logger->info('Filesystem cache maintenance completed', $cleanupStats);

            // Perform directory optimization if requested
            if ($options['optimize_directories'] ?? false) {
                $this->directoryManager->optimizeStructure();
            }

            // Validate filesystem health
            if ($options['validate_health'] ?? true) {
                $healthCheck = $this->validator->validateHealth();
                if (!$healthCheck['healthy']) {
                    $this->logger->warning('Filesystem health check failed', $healthCheck);

                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Filesystem cache maintenance failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Gets detailed cache statistics.
     *
     * @return array<string, mixed> Comprehensive cache statistics
     */
    public function getDetailedStats(): array
    {
        return [
            'driver_type' => 'filesystem',
            'capabilities' => $this->getCapabilities(),
            'filesystem_stats' => $this->statistics->getStats(),
            'directory_info' => $this->directoryManager->getInfo(),
            'validator_status' => $this->validator->getStatus(),
        ];
    }

    /**
     * Gets current cache metrics.
     *
     * @return MetricsSnapshot Current metrics snapshot
     */
    public function getMetrics(): MetricsSnapshot
    {
        return $this->statistics->getMetrics();
    }

    /**
     * Performs a health check on the filesystem cache.
     *
     * @return array<string, mixed> Health check results
     */
    public function healthCheck(): array
    {
        return $this->validator->validateHealth();
    }

    /**
     * Optimizes the cache directory structure.
     *
     * @return bool true if optimization was successful
     */
    public function optimizeDirectoryStructure(): bool
    {
        try {
            return $this->directoryManager->optimizeStructure();
        } catch (\Throwable $e) {
            $this->logger->error('Directory structure optimization failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Gets filesystem usage information.
     *
     * @return array<string, mixed> Usage information
     */
    public function getUsageInfo(): array
    {
        return [
            'directory_info' => $this->directoryManager->getInfo(),
            'statistics' => $this->statistics->getStats(),
            'health_status' => $this->validator->getStatus(),
        ];
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Resolves expiration time using ExpirationProvider strategy.
     *
     * @param string $key The cache key for context
     * @param \DateInterval|int|null $ttl The provided TTL
     * @param string $operation The operation type for context
     *
     * @return \DateTimeInterface|null The resolved expiration time
     */
    private function resolveExpiration(string $key, \DateInterval|int|null $ttl, string $operation): ?\DateTimeInterface
    {
        // Handle explicit null (use provider defaults)
        if (null === $ttl) {
            $providerTtl = $this->expirationProvider->getTtlForOperation($operation);

            return $this->expirationProvider->calculateExpiration($providerTtl);
        }

        // Handle DateInterval
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }

        // Handle integer (seconds)
        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return null; // No expiration for non-positive values
            }

            return new \DateTimeImmutable("+{$ttl} seconds");
        }

        // Fallback to ExpirationProvider
        $providerTtl = $this->expirationProvider->getTtlForKey($key);

        return $this->expirationProvider->calculateExpiration($providerTtl);
    }
}
