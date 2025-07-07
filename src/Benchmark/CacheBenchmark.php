<?php

declare(strict_types=1);

namespace KaririCode\Cache\Benchmark;

use KaririCode\Cache\Contract\Cache;

/**
 * Cache performance benchmark utility.
 */
final class CacheBenchmark
{
    private Cache $cache;
    private array $results = [];

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function runBenchmark(array $config = []): array
    {
        $config = array_merge([
            'iterations' => 1000,
            'key_size' => 50,
            'value_size' => 1024,
            'include_complex_types' => true,
            'test_concurrent' => false,
            'warm_up' => true,
        ], $config);

        $this->results = [];

        if ($config['warm_up']) {
            $this->warmUp($config['iterations'] / 10);
        }

        $this->benchmarkBasicOperations($config);
        $this->benchmarkBatchOperations($config);
        $this->benchmarkComplexOperations($config);

        if ($config['include_complex_types']) {
            $this->benchmarkComplexTypes($config);
        }

        if ($config['test_concurrent']) {
            $this->benchmarkConcurrentOperations($config);
        }

        return $this->results;
    }

    private function warmUp(int $iterations): void
    {
        for ($i = 0; $i < $iterations; $i++) {
            $key = "warmup_$i";
            $this->cache->set($key, "warmup_value_$i");
            $this->cache->get($key);
            $this->cache->delete($key);
        }
    }

    private function benchmarkBasicOperations(array $config): void
    {
        $iterations = $config['iterations'];
        $valueSize = $config['value_size'];
        $testValue = str_repeat('x', $valueSize);

        // Set operations
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->set("bench_set_$i", $testValue);
        }
        $setTime = microtime(true) - $start;

        // Get operations
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->get("bench_set_$i");
        }
        $getTime = microtime(true) - $start;

        // Delete operations
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->delete("bench_set_$i");
        }
        $deleteTime = microtime(true) - $start;

        // Has operations
        for ($i = 0; $i < $iterations / 2; $i++) {
            $this->cache->set("bench_has_$i", $testValue);
        }

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->has("bench_has_$i");
        }
        $hasTime = microtime(true) - $start;

        // Clean up
        for ($i = 0; $i < $iterations / 2; $i++) {
            $this->cache->delete("bench_has_$i");
        }

        $this->results['basic_operations'] = [
            'set' => [
                'total_time' => $setTime,
                'operations_per_second' => $iterations / $setTime,
                'average_time' => $setTime / $iterations,
            ],
            'get' => [
                'total_time' => $getTime,
                'operations_per_second' => $iterations / $getTime,
                'average_time' => $getTime / $iterations,
            ],
            'delete' => [
                'total_time' => $deleteTime,
                'operations_per_second' => $iterations / $deleteTime,
                'average_time' => $deleteTime / $iterations,
            ],
            'has' => [
                'total_time' => $hasTime,
                'operations_per_second' => $iterations / $hasTime,
                'average_time' => $hasTime / $iterations,
            ],
        ];
    }

    private function benchmarkBatchOperations(array $config): void
    {
        $iterations = min($config['iterations'], 100); // Limit batch size
        $testData = [];

        for ($i = 0; $i < $iterations; $i++) {
            $testData["batch_$i"] = "value_$i";
        }

        // setMultiple
        $start = microtime(true);
        $this->cache->setMultiple($testData);
        $setMultipleTime = microtime(true) - $start;

        // getMultiple
        $start = microtime(true);
        $result = $this->cache->getMultiple(array_keys($testData));
        $getMultipleTime = microtime(true) - $start;

        // deleteMultiple
        $start = microtime(true);
        $this->cache->deleteMultiple(array_keys($testData));
        $deleteMultipleTime = microtime(true) - $start;

        $this->results['batch_operations'] = [
            'setMultiple' => [
                'total_time' => $setMultipleTime,
                'items_per_second' => $iterations / $setMultipleTime,
                'average_time' => $setMultipleTime / $iterations,
            ],
            'getMultiple' => [
                'total_time' => $getMultipleTime,
                'items_per_second' => $iterations / $getMultipleTime,
                'average_time' => $getMultipleTime / $iterations,
            ],
            'deleteMultiple' => [
                'total_time' => $deleteMultipleTime,
                'items_per_second' => $iterations / $deleteMultipleTime,
                'average_time' => $deleteMultipleTime / $iterations,
            ],
        ];
    }

    private function benchmarkComplexOperations(array $config): void
    {
        $iterations = $config['iterations'];

        // Increment operations
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->increment("counter_$i", 1, 0);
        }
        $incrementTime = microtime(true) - $start;

        // Add operations
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->add("add_test_$i", "value_$i");
        }
        $addTime = microtime(true) - $start;

        // Clean up
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->delete("counter_$i");
            $this->cache->delete("add_test_$i");
        }

        $this->results['complex_operations'] = [
            'increment' => [
                'total_time' => $incrementTime,
                'operations_per_second' => $iterations / $incrementTime,
                'average_time' => $incrementTime / $iterations,
            ],
            'add' => [
                'total_time' => $addTime,
                'operations_per_second' => $iterations / $addTime,
                'average_time' => $addTime / $iterations,
            ],
        ];
    }

    private function benchmarkComplexTypes(array $config): void
    {
        $iterations = min($config['iterations'], 100);
        $complexData = [
            'array' => range(1, 100),
            'object' => (object) ['prop1' => 'value1', 'prop2' => 'value2'],
            'nested' => [
                'level1' => [
                    'level2' => [
                        'level3' => 'deep_value'
                    ]
                ]
            ]
        ];

        $results = [];

        foreach ($complexData as $type => $data) {
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $this->cache->set("complex_{$type}_$i", $data);
            }
            $setTime = microtime(true) - $start;

            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $this->cache->get("complex_{$type}_$i");
            }
            $getTime = microtime(true) - $start;

            // Clean up
            for ($i = 0; $i < $iterations; $i++) {
                $this->cache->delete("complex_{$type}_$i");
            }

            $results[$type] = [
                'set' => [
                    'total_time' => $setTime,
                    'operations_per_second' => $iterations / $setTime,
                ],
                'get' => [
                    'total_time' => $getTime,
                    'operations_per_second' => $iterations / $getTime,
                ],
            ];
        }

        $this->results['complex_types'] = $results;
    }

    private function benchmarkConcurrentOperations(array $config): void
    {
        // Note: This is a simplified concurrent test
        // In a real scenario, you'd use multi-threading or multi-processing
        $iterations = $config['iterations'];
        $processes = 4;
        $iterationsPerProcess = $iterations / $processes;

        $start = microtime(true);
        for ($p = 0; $p < $processes; $p++) {
            for ($i = 0; $i < $iterationsPerProcess; $i++) {
                $key = "concurrent_p{$p}_i{$i}";
                $this->cache->set($key, "value_p{$p}_i{$i}");
                $this->cache->get($key);
                $this->cache->delete($key);
            }
        }
        $concurrentTime = microtime(true) - $start;

        $this->results['concurrent_operations'] = [
            'total_time' => $concurrentTime,
            'operations_per_second' => $iterations / $concurrentTime,
            'simulated_processes' => $processes,
        ];
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function generateReport(): string
    {
        $report = "Cache Performance Benchmark Report\n";
        $report .= "==================================\n\n";

        foreach ($this->results as $category => $data) {
            $report .= ucfirst(str_replace('_', ' ', $category)) . ":\n";
            $report .= str_repeat('-', strlen($category) + 1) . "\n";

            if ($category === 'complex_types') {
                foreach ($data as $type => $typeData) {
                    $report .= "  {$type}:\n";
                    foreach ($typeData as $operation => $stats) {
                        $report .= "    {$operation}: {$stats['operations_per_second']:.2f} ops/sec\n";
                    }
                }
            } else {
                foreach ($data as $operation => $stats) {
                    if (is_array($stats) && isset($stats['operations_per_second'])) {
                        $report .= "  {$operation}: {$stats['operations_per_second']:.2f} ops/sec\n";
                    }
                }
            }
            $report .= "\n";
        }

        return $report;
    }
}
