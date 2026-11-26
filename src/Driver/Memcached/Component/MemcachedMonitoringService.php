<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Component;

use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\ValueObject\MetricsSnapshot;
use KaririCode\Contract\Logging\Logger;

/**
 * Monitoring service for Memcached operations.
 * This class implements the DriverStats interface for server-side stats
 * and also provides methods to record client-side operation counts.
 */
final class MemcachedMonitoringService implements DriverStats
{
    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;
    private int $deletes = 0;
    private int $errors = 0;

    public function __construct(
        private \Memcached $memcached,
        private Logger $logger
    ) {
    }

    // --- Recording Methods (for client-side monitoring) ---

    public function recordHit(): void
    {
        ++$this->hits;
    }

    public function recordMiss(): void
    {
        ++$this->misses;
    }

    public function recordWrite(): void
    {
        ++$this->writes;
    }

    public function recordDelete(): void
    {
        ++$this->deletes;
    }

    public function recordError(): void
    {
        ++$this->errors;
    }

    public function recordClear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->writes = 0;
        $this->deletes = 0;
        $this->errors = 0;
    }

    // --- DriverStats Interface Implementation ---

    public function getDriverName(): string
    {
        return 'Memcached';
    }

    public function getDriverVersion(): ?string
    {
        /** @var array<string, string>|false $versions */
        $versions = $this->memcached->getVersion();

        if (false === $versions) {
            return null;
        }

        return (string) reset($versions);
    }

    public function getMemoryUsage(): ?int
    {
        $stats = $this->getRawStats();
        if (empty($stats)) {
            return null;
        }

        $totalBytes = 0;
        foreach ($stats as $serverStats) {
            // Guard clause to ensure we're working with a valid array.
            if (!is_array($serverStats)) {
                continue;
            }

            if (isset($serverStats['bytes']) && is_numeric($serverStats['bytes'])) {
                $totalBytes += (int) $serverStats['bytes'];
            }
        }

        return $totalBytes;
    }

    public function getItemCount(): ?int
    {
        $stats = $this->getRawStats();
        if (empty($stats)) {
            return null;
        }

        $totalItems = 0;
        foreach ($stats as $serverStats) {
            // Guard clause to ensure we're working with a valid array.
            if (!is_array($serverStats)) {
                continue;
            }

            if (isset($serverStats['curr_items']) && is_numeric($serverStats['curr_items'])) {
                $totalItems += (int) $serverStats['curr_items'];
            }
        }

        return $totalItems;
    }

    public function getRawStats(): array
    {
        /** @var array<string, array<string, mixed>>|false $stats */
        $stats = $this->memcached->getStats();

        if (false === $stats) {
            return [];
        }

        return $stats;
    }

    public function getDriverStats(): DriverStats
    {
        return new MetricsSnapshot(
            $this->getDriverName(),
            $this->getMemoryUsage(),
            $this->getItemCount(),
            $this->getDriverVersion(),
            $this->getRawStats()
        );
    }

    // --- Management & Health Check Methods ---

    /**
     * @return array{status: string, nodes: array<string, mixed>}
     */
    public function getHealthCheck(): array
    {
        /** @var array<string, string|false>|false $versions */
        $versions = $this->memcached->getVersion();

        if (false === $versions) {
            return ['status' => 'unhealthy', 'nodes' => []];
        }

        $nodes = [];
        foreach ($versions as $server => $version) {
            $nodes[$server] = [
                'status' => false !== $version ? 'healthy' : 'unhealthy',
                'version' => $version ?: 'unknown',
            ];
        }

        $healthyNodes = array_filter($nodes, fn ($node) => 'healthy' === $node['status']);
        $overallStatus = !empty($healthyNodes) ? 'healthy' : 'unhealthy';

        return [
            'status' => $overallStatus,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function maintenance(array $options = []): bool
    {
        $this->logger->info('Memcached maintenance: flush operation is handled automatically by the server TTLs.');

        return true;
    }
}
