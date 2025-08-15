<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\DTO\HealthCheckResult;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\SilentLogger;

/**
 * Service for running health checks on the cache system.
 * It provides a way to verify the availability and performance of cache stores.
 * This version is decoupled from the high-level Cache facade.
 */
class HealthCheckService
{
    /** @var array<string, callable> Registered checks. */
    private array $checks = [];

    public function __construct(
        private readonly LayeredDriver $driver,
        private readonly MetricsService $metrics,
        private readonly Logger $logger = new SilentLogger()
    ) {
        $this->registerDefaultChecks();
    }

    /**
     * Checks if the overall cache system is healthy by running all checks.
     */
    public function isHealthy(): bool
    {
        foreach ($this->runAllChecks() as $result) {
            if (!$result->isHealthy) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs all registered health checks and returns their results.
     *
     * @return array<string, HealthCheckResult>
     */
    public function runAllChecks(): array
    {
        $results = [];
        foreach ($this->checks as $name => $check) {
            $results[$name] = $this->runCheck($name, $check);
        }

        return $results;
    }

    /**
     * Gathers full diagnostic information, including health checks and metrics.
     *
     * @return array<string, mixed>
     */
    public function getDiagnostics(): array
    {
        $healthResults = $this->runAllChecks();

        return [
            'health_status' => $this->isHealthy() ? 'HEALTHY' : 'UNHEALTHY',
            'timestamp' => time(),
            'checks' => array_map(fn (HealthCheckResult $r) => [
                'check_name' => $r->checkName,
                'is_healthy' => $r->isHealthy,
                'message' => $r->message,
                'duration_ms' => $r->durationMs,
                'timestamp' => $r->timestamp,
            ], $healthResults),
            'metrics' => $this->metrics->getMetrics(),
        ];
    }

    /**
     * Executes a single health check.
     */
    private function runCheck(string $name, callable $check): HealthCheckResult
    {
        $startTime = microtime(true);
        try {
            $result = $check();
            $isHealthy = is_bool($result) ? $result : ($result['healthy'] ?? true);
            $message = is_array($result) ? ($result['message'] ?? 'OK') : 'OK';

            return new HealthCheckResult(
                checkName: $name,
                isHealthy: $isHealthy,
                message: $message,
                durationMs: round((microtime(true) - $startTime) * 1000, 2),
                timestamp: time()
            );
        } catch (\Throwable $e) {
            $this->logger->error('Health check "{check}" failed with an exception.', [
                'check' => $name,
                'error' => $e->getMessage(),
            ]);

            return new HealthCheckResult(
                checkName: $name,
                isHealthy: false,
                message: $e->getMessage(),
                durationMs: round((microtime(true) - $startTime) * 1000, 2),
                timestamp: time()
            );
        }
    }

    /**
     * Registers the default set of health checks for the cache system.
     */
    private function registerDefaultChecks(): void
    {
        // Connectivity check now uses the PSR-6 interface methods from Layereddriver
        $this->checks['connectivity'] = function () {
            $key = '__health_check:' . uniqid();
            $item = $this->driver->getItem($key);
            $item->set('ok');
            $item->expiresAfter(5);

            if (!$this->driver->save($item)) {
                return ['healthy' => false, 'message' => 'Failed to write to cache'];
            }
            if (!$this->driver->hasItem($key)) {
                return ['healthy' => false, 'message' => 'Write successful, but item not found'];
            }
            $this->driver->deleteItem($key);

            return true;
        };

        // Hit rate check remains the same as it correctly uses MetricsService
        $this->checks['hit_rate'] = function () {
            $metrics = $this->metrics->getMetrics();
            $hitRate = $metrics['hit_rate'] ?? 0.0;
            $totalOps = $metrics['total_operations'] ?? 0;

            if ($totalOps > 100 && $hitRate < 0.8) { // 80% threshold
                return ['healthy' => false, 'message' => sprintf('Low hit rate: %.2f%%', $hitRate * 100)];
            }

            return ['healthy' => true, 'message' => sprintf('Hit rate is %.2f%%', $hitRate * 100)];
        };
    }
}
