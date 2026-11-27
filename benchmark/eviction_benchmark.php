<?php

declare(strict_types=1);

/**
 * Eviction and XFetch benchmark for KaririCode Cache, run on the real
 * policy classes.
 *
 * Usage: php benchmark/eviction_benchmark.php   (BENCH_SEED=n to change the seed)
 *
 * Workloads (100 000 requests, 10 000 keys):
 *   zipf-1.0   stationary Zipf, alpha 1.0
 *   zipf-0.8   stationary Zipf, alpha 0.8
 *   shifting   Zipf 1.0 whose popularity ranking is reshuffled every 25 000 requests
 *   scans      Zipf 1.0 with a burst of 1 000 one-time keys every 5 000 requests
 * Miss ratio = misses / requests of a simulated cache that admits every
 * missed key and evicts through the policy. Miss ratios depend only on the
 * seed — the XFetch check included, through a seeded Random\Randomizer —;
 * only throughput depends on the machine.
 */

require __DIR__ . '/../vendor/autoload.php';

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Eviction\FifoPolicy;
use KaririCode\Cache\Eviction\LfuPolicy;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Stampede\XFetchPrevention;
use Random\Engine\Mt19937;
use Random\Randomizer;

// ── Workloads ────────────────────────────────────────────────────────

/**
 * @return list<int> ranks in [1, $keys]
 */
function zipfRanks(int $requests, int $keys, float $alpha): array
{
    $cdf = [];
    $sum = 0.0;
    for ($i = 1; $i <= $keys; ++$i) {
        $sum += 1.0 / ($i ** $alpha);
        $cdf[$i] = $sum;
    }

    $ranks = [];
    for ($r = 0; $r < $requests; ++$r) {
        $u = mt_rand() / mt_getrandmax() * $sum;
        $lo = 1;
        $hi = $keys;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $cdf[$mid] < $u ? $lo = $mid + 1 : $hi = $mid;
        }
        $ranks[] = $lo;
    }

    return $ranks;
}

/**
 * @return array<string, list<string>>
 */
function workloads(int $requests, int $keys): array
{
    $stationary = zipfRanks($requests, $keys, 1.0);

    $shifting = [];
    $mapping = range(1, $keys);
    foreach ($stationary as $i => $rank) {
        if (0 === $i % 25_000) {
            shuffle($mapping);
        }
        $shifting[] = 'k' . $mapping[$rank - 1];
    }

    $scans = [];
    $scanId = 0;
    foreach ($stationary as $i => $rank) {
        if (0 === $i % 5_000 && $i > 0) {
            for ($s = 0; $s < 1_000; ++$s) {
                $scans[] = 's' . $scanId++;
            }
        }
        $scans[] = 'k' . $rank;
    }

    return [
        'zipf-1.0' => array_map(static fn(int $r): string => 'k' . $r, $stationary),
        'zipf-0.8' => array_map(static fn(int $r): string => 'k' . $r, zipfRanks($requests, $keys, 0.8)),
        'shifting' => $shifting,
        'scans' => $scans,
    ];
}

// ── Simulation ───────────────────────────────────────────────────────

/**
 * @param list<string> $trace
 */
function missRatio(EvictionPolicy $policy, array $trace, int $capacity): float
{
    $cached = [];
    $misses = 0;

    foreach ($trace as $key) {
        if (isset($cached[$key])) {
            $policy->recordAccess($key);
            continue;
        }

        ++$misses;
        while ($policy->count() >= $capacity) {
            $evicted = $policy->evict();
            if (!$evicted->success || null === $evicted->evictedKey) {
                throw new LogicException($policy->name() . ' could not evict');
            }
            unset($cached[$evicted->evictedKey]);
        }
        $policy->recordInsertion($key);
        $cached[$key] = true;
    }

    return $misses / count($trace);
}

/**
 * @return array<string, EvictionPolicy>
 */
function policies(): array
{
    return ['SIEVE' => new SievePolicy(), 'LRU' => new LruPolicy(), 'LFU' => new LfuPolicy(), 'FIFO' => new FifoPolicy()];
}

// ── Run ──────────────────────────────────────────────────────────────

$seed = (int) (getenv('BENCH_SEED') ?: 42);
mt_srand($seed);
$requests = 100_000;
$keys = 10_000;
$capacities = [100, 500, 1_000, 2_000];

printf("KaririCode Cache eviction benchmark — PHP %s, seed %d, %d requests, %d keys\n\n", PHP_VERSION, $seed, $requests, $keys);
printf("Miss ratio (lower is better); last column: SIEVE relative to LRU\n");
printf("%-9s %6s %8s %8s %8s %8s %10s\n", 'workload', 'size', 'SIEVE', 'LRU', 'LFU', 'FIFO', 'SIEVE/LRU');

foreach (workloads($requests, $keys) as $name => $trace) {
    foreach ($capacities as $capacity) {
        $ratios = [];
        foreach (policies() as $policyName => $policy) {
            $ratios[$policyName] = missRatio($policy, $trace, $capacity);
        }
        printf(
            "%-9s %6d %7.2f%% %7.2f%% %7.2f%% %7.2f%% %+9.1f%%\n",
            $name,
            $capacity,
            100 * $ratios['SIEVE'],
            100 * $ratios['LRU'],
            100 * $ratios['LFU'],
            100 * $ratios['FIFO'],
            100 * ($ratios['SIEVE'] - $ratios['LRU']) / $ratios['LRU'],
        );
    }
}

// Throughput: 500 000 operations, 70 % hits, cache of 1 000 keys.
printf("\nThroughput (500 000 ops, 70%% hits, 1 000 entries; machine-dependent)\n");
foreach (policies() as $policyName => $policy) {
    for ($i = 0; $i < 1_000; ++$i) {
        $policy->recordInsertion('w' . $i);
    }
    mt_srand($seed);
    $start = hrtime(true);
    for ($op = 0; $op < 500_000; ++$op) {
        if (mt_rand(1, 10) <= 7) {
            $policy->recordAccess('w' . mt_rand(0, 999));
        } else {
            $evicted = $policy->evict();
            $policy->recordInsertion((string) $evicted->evictedKey);
        }
    }
    $seconds = (hrtime(true) - $start) / 1e9;
    printf("%-6s %12s ops/s\n", $policyName, number_format(500_000 / $seconds));
}

// XFetch: observed P(recompute) versus exp(-gap / (delta * beta)).
printf("\nXFetch P(recompute), beta = 1, delta = 1 s, 100 000 samples per gap\n");
printf("%-8s %10s %10s %10s\n", 'gap (s)', 'observed', 'expected', 'abs error');
$xfetch = new XFetchPrevention(beta: 1.0, randomizer: new Randomizer(new Mt19937($seed)));
$maxError = 0.0;
foreach ([0.1, 0.5, 1.0, 2.0, 3.0, 5.0] as $gap) {
    $hits = 0;
    for ($i = 0; $i < 100_000; ++$i) {
        $hits += $xfetch->shouldRecompute(key: 'bench', currentTime: 1000.0 - $gap, expiryTime: 1000.0, delta: 1.0) ? 1 : 0;
    }
    $observed = $hits / 100_000;
    $expected = exp(-$gap);
    $maxError = max($maxError, abs($observed - $expected));
    printf("%-8.1f %10.4f %10.4f %10.4f\n", $gap, $observed, $expected, abs($observed - $expected));
}
printf("max abs error: %.4f\n", $maxError);
