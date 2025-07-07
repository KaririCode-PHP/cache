<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;

/**
 * Business intelligence event listener for analytics and insights.
 */
final class AnalyticsEventListener implements CacheEventListener
{
    private array $analytics = [];
    private array $patterns = [];

    public function onCacheHit(string $key, mixed $value): void
    {
        $this->recordAnalytic('hit', $key, $value);
        $this->analyzeAccessPattern($key, 'hit');
    }

    public function onCacheMiss(string $key): void
    {
        $this->recordAnalytic('miss', $key);
        $this->analyzeAccessPattern($key, 'miss');
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $this->recordAnalytic('set', $key, $value);
    }

    public function onCacheDelete(string $key): void
    {
        $this->recordAnalytic('delete', $key);
    }

    public function onCacheClear(): void
    {
        $this->recordAnalytic('clear', 'all');
    }

    public function getBusinessInsights(): array
    {
        return [
            'cache_efficiency' => $this->calculateCacheEfficiency(),
            'popular_data_types' => $this->getPopularDataTypes(),
            'access_patterns' => $this->getAccessPatterns(),
            'recommendations' => $this->generateRecommendations(),
            'trending_keys' => $this->getTrendingKeys(),
        ];
    }

    private function recordAnalytic(string $operation, string $key, mixed $value = null): void
    {
        $this->analytics[] = [
            'operation' => $operation,
            'key' => $key,
            'timestamp' => time(),
            'hour' => (int) date('H'),
            'day_of_week' => (int) date('w'),
            'value_type' => $value ? gettype($value) : null,
            'value_size' => $value ? strlen(serialize($value)) : 0,
        ];
    }

    private function analyzeAccessPattern(string $key, string $type): void
    {
        $pattern = $this->extractKeyPattern($key);

        if (!isset($this->patterns[$pattern])) {
            $this->patterns[$pattern] = ['hits' => 0, 'misses' => 0];
        }

        ++$this->patterns[$pattern]['hit' === $type ? 'hits' : 'misses'];
    }

    private function extractKeyPattern(string $key): string
    {
        // Extract patterns like "user:*", "session:*", etc.
        if (preg_match('/^([a-zA-Z_]+):[^:]+/', $key, $matches)) {
            return $matches[1] . ':*';
        }

        if (preg_match('/^([a-zA-Z_]+)\d+/', $key, $matches)) {
            return $matches[1] . '*';
        }

        return 'other';
    }

    private function calculateCacheEfficiency(): array
    {
        $hits = count(array_filter($this->analytics, fn ($a) => 'hit' === $a['operation']));
        $misses = count(array_filter($this->analytics, fn ($a) => 'miss' === $a['operation']));
        $total = $hits + $misses;

        return [
            'hit_rate' => $total > 0 ? $hits / $total : 0,
            'total_requests' => $total,
            'hits' => $hits,
            'misses' => $misses,
        ];
    }

    private function getPopularDataTypes(): array
    {
        $types = [];
        foreach ($this->analytics as $analytic) {
            if ($analytic['value_type']) {
                $types[$analytic['value_type']] = ($types[$analytic['value_type']] ?? 0) + 1;
            }
        }
        arsort($types);

        return $types;
    }

    private function getAccessPatterns(): array
    {
        return $this->patterns;
    }

    private function generateRecommendations(): array
    {
        $recommendations = [];

        // Analyze cache efficiency
        $efficiency = $this->calculateCacheEfficiency();
        if ($efficiency['hit_rate'] < 0.7) {
            $recommendations[] = "Low cache hit rate ({$efficiency['hit_rate']}). Consider increasing TTL or reviewing cache strategy.";
        }

        // Analyze patterns for optimization opportunities
        foreach ($this->patterns as $pattern => $stats) {
            $total = $stats['hits'] + $stats['misses'];
            if ($total > 10 && ($stats['misses'] / $total) > 0.5) {
                $recommendations[] = "Pattern '{$pattern}' has high miss rate. Consider preloading or different caching strategy.";
            }
        }

        return $recommendations;
    }

    private function getTrendingKeys(): array
    {
        $recentAnalytics = array_filter($this->analytics, function ($analytic) {
            return $analytic['timestamp'] > time() - 3600; // Last hour
        });

        $keyStats = [];
        foreach ($recentAnalytics as $analytic) {
            $key = $analytic['key'];
            $keyStats[$key] = ($keyStats[$key] ?? 0) + 1;
        }

        arsort($keyStats);

        return array_slice($keyStats, 0, 10, true);
    }
}
