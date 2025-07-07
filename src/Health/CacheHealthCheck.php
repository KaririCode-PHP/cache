<?php

declare(strict_types=1);

namespace KaririCode\Cache\Health;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheHealthCheck as CacheHealthCheckContract;

/**
 * Health check implementation for cache drivers.
 */
final class CacheHealthCheck implements CacheHealthCheckContract
{
    private ?string $lastError = null;
    private array $healthStatus = [];

    public function __construct(private CacheDriver $driver)
    {
    }

    public function isHealthy(): bool
    {
        try {
            $testKey = 'health_check_' . uniqid();
            $testValue = 'test_' . time();

            // Test write
            if (!$this->driver->set($testKey, $testValue)) {
                $this->lastError = 'Failed to write test value';

                return false;
            }

            // Test read
            $retrievedValue = $this->driver->get($testKey);
            if ($retrievedValue !== $testValue) {
                $this->lastError = 'Retrieved value does not match written value';

                return false;
            }

            // Test delete
            if (!$this->driver->delete($testKey)) {
                $this->lastError = 'Failed to delete test value';

                return false;
            }

            // Test that value is actually deleted
            if ($this->driver->has($testKey)) {
                $this->lastError = 'Value still exists after deletion';

                return false;
            }

            $this->lastError = null;

            return true;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    public function getHealthStatus(): array
    {
        $isHealthy = $this->isHealthy();

        $this->healthStatus = [
            'healthy' => $isHealthy,
            'driver' => get_class($this->driver),
            'last_check' => date('Y-m-d H:i:s'),
            'last_error' => $this->lastError,
        ];

        if ($isHealthy) {
            $this->healthStatus['operations'] = $this->testOperations();
        }

        return $this->healthStatus;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function testOperations(): array
    {
        $operations = [];
        $testKey = 'perf_test_' . uniqid();

        // Test write performance
        $start = microtime(true);
        $this->driver->set($testKey, 'performance_test_data');
        $operations['write_time'] = microtime(true) - $start;

        // Test read performance
        $start = microtime(true);
        $this->driver->get($testKey);
        $operations['read_time'] = microtime(true) - $start;

        // Test exists check performance
        $start = microtime(true);
        $this->driver->has($testKey);
        $operations['exists_time'] = microtime(true) - $start;

        // Test delete performance
        $start = microtime(true);
        $this->driver->delete($testKey);
        $operations['delete_time'] = microtime(true) - $start;

        // Test increment if supported
        if (method_exists($this->driver, 'increment')) {
            $incKey = 'inc_test_' . uniqid();
            $start = microtime(true);
            $this->driver->increment($incKey, 1, 0);
            $operations['increment_time'] = microtime(true) - $start;
            $this->driver->delete($incKey);
        }

        return $operations;
    }
}
