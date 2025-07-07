<?php

declare(strict_types=1);

namespace KaririCode\Cache\Console;

/**
 * Console utilities for cache management.
 */
final class CacheConsole
{
    private \KaririCode\Cache\Contract\Cache $cache;

    public function __construct(\KaririCode\Cache\Contract\Cache $cache)
    {
        $this->cache = $cache;
    }

    public function clearCache(): bool
    {
        return $this->cache->clear();
    }

    public function showMetrics(): void
    {
        $metrics = $this->cache->getMetrics();

        echo "Cache Metrics:\n";
        echo "==============\n";

        foreach ($metrics as $key => $value) {
            if (is_array($value)) {
                echo "$key:\n";
                foreach ($value as $subKey => $subValue) {
                    echo "  $subKey: $subValue\n";
                }
            } else {
                echo "$key: $value\n";
            }
        }
    }

    public function warmCache(array $data, ?int $ttl = null): void
    {
        $this->cache->warm($data, $ttl);
        echo 'Cache warmed with ' . count($data) . " items\n";
    }

    public function testCache(): array
    {
        $test = new \KaririCode\Cache\Test\CacheTest($this->cache);

        return $test->runAllTests();
    }

    public function benchmarkCache(array $config = []): void
    {
        $benchmark = new \KaririCode\Cache\Benchmark\CacheBenchmark($this->cache);
        $results = $benchmark->runBenchmark($config);
        echo $benchmark->generateReport();
    }

    public function showCacheInfo(): void
    {
        echo "Cache Information:\n";
        echo "==================\n";
        echo 'Cache Type: ' . get_class($this->cache) . "\n";

        if (method_exists($this->cache, 'getMetrics')) {
            $metrics = $this->cache->getMetrics();
            echo 'Hit Rate: ' . number_format(($metrics['hit_rate'] ?? 0) * 100, 2) . "%\n";
            echo 'Total Operations: ' . ($metrics['total_operations'] ?? 0) . "\n";
            echo 'Hits: ' . ($metrics['hits'] ?? 0) . "\n";
            echo 'Misses: ' . ($metrics['misses'] ?? 0) . "\n";
        }
    }
}
