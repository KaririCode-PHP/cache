<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Contract\Cache;

/**
 * Specialized cache for API responses with smart invalidation.
 */
final class ApiResponseCache
{
    private Cache $cache;
    private string $prefix;
    private array $config;

    public function __construct(Cache $cache, string $prefix = 'api:', array $config = [])
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->config = array_merge([
            'default_ttl' => 3600,
            'max_response_size' => 1024 * 1024, // 1MB
            'compress_responses' => true,
            'cache_headers' => true,
            'vary_on_headers' => ['Accept', 'Accept-Language'],
        ], $config);
    }

    public function getResponse(string $endpoint, array $params = [], array $headers = []): ?array
    {
        $key = $this->generateKey($endpoint, $params, $headers);
        $cached = $this->cache->get($key);

        if ($cached && $this->isValidResponse($cached)) {
            return $cached;
        }

        return null;
    }

    public function cacheResponse(string $endpoint, array $params, array $headers, array $response, ?int $ttl = null): bool
    {
        if ($this->shouldCacheResponse($response)) {
            $key = $this->generateKey($endpoint, $params, $headers);
            $ttl = $ttl ?? $this->calculateTtl($response);

            $cacheData = [
                'response' => $response,
                'cached_at' => time(),
                'endpoint' => $endpoint,
                'params' => $params,
                'headers' => $this->config['cache_headers'] ? $headers : [],
            ];

            $tags = $this->generateTags($endpoint, $params);

            if (method_exists($this->cache, 'setWithTags')) {
                return $this->cache->setWithTags($key, $cacheData, $ttl, $tags);
            }

            return $this->cache->set($key, $cacheData, $ttl);
        }

        return false;
    }

    public function invalidateEndpoint(string $endpoint): bool
    {
        $tag = $this->prefix . 'endpoint:' . hash('sha256', $endpoint);

        if (method_exists($this->cache, 'invalidateTags')) {
            return $this->cache->invalidateTags([$tag]);
        }

        return true;
    }

    public function invalidatePattern(string $pattern): bool
    {
        $tag = $this->prefix . 'pattern:' . hash('sha256', $pattern);

        if (method_exists($this->cache, 'invalidateTags')) {
            return $this->cache->invalidateTags([$tag]);
        }

        return true;
    }

    public function warmUpEndpoint(string $endpoint, array $parameterSets = [], ?callable $fetcher = null): array
    {
        $results = [];

        foreach ($parameterSets as $params) {
            $key = $this->generateKey($endpoint, $params);

            if (!$this->cache->has($key) && $fetcher) {
                try {
                    $response = $fetcher($endpoint, $params);
                    $this->cacheResponse($endpoint, $params, [], $response);
                    $results[$key] = ['success' => true, 'cached' => true];
                } catch (\Exception $e) {
                    $results[$key] = ['success' => false, 'error' => $e->getMessage()];
                }
            } else {
                $results[$key] = ['success' => true, 'cached' => false];
            }
        }

        return $results;
    }

    public function getStats(): array
    {
        $metrics = method_exists($this->cache, 'getMetrics') ? $this->cache->getMetrics() : [];

        return [
            'cache_metrics' => $metrics,
            'config' => $this->config,
            'prefix' => $this->prefix,
        ];
    }

    private function generateKey(string $endpoint, array $params = [], array $headers = []): string
    {
        $keyParts = [$endpoint];

        if (!empty($params)) {
            ksort($params);
            $keyParts[] = http_build_query($params);
        }

        foreach ($this->config['vary_on_headers'] as $header) {
            if (isset($headers[$header])) {
                $keyParts[] = $header . ':' . $headers[$header];
            }
        }

        return $this->prefix . hash('sha256', implode('|', $keyParts));
    }

    private function generateTags(string $endpoint, array $params): array
    {
        $tags = [];

        // Tag by endpoint
        $tags[] = $this->prefix . 'endpoint:' . hash('sha256', $endpoint);

        // Tag by resource type (extract from endpoint)
        if (preg_match('/\/api\/v\d+\/([^\/]+)/', $endpoint, $matches)) {
            $tags[] = $this->prefix . 'resource:' . $matches[1];
        }

        // Tag by specific parameters
        if (isset($params['id'])) {
            $tags[] = $this->prefix . 'id:' . $params['id'];
        }

        if (isset($params['user_id'])) {
            $tags[] = $this->prefix . 'user:' . $params['user_id'];
        }

        return $tags;
    }

    private function shouldCacheResponse(array $response): bool
    {
        // Don't cache error responses
        if (isset($response['error']) || isset($response['errors'])) {
            return false;
        }

        // Don't cache responses that are too large
        $size = strlen(serialize($response));
        if ($size > $this->config['max_response_size']) {
            return false;
        }

        // Don't cache responses with cache-control headers preventing caching
        if (isset($response['headers']['Cache-Control'])) {
            $cacheControl = $response['headers']['Cache-Control'];
            if (false !== strpos($cacheControl, 'no-cache') || false !== strpos($cacheControl, 'no-store')) {
                return false;
            }
        }

        return true;
    }

    private function isValidResponse(array $cached): bool
    {
        // Check if the response structure is valid
        if (!isset($cached['response'], $cached['cached_at'])) {
            return false;
        }

        // Additional validation can be added here
        return true;
    }

    private function calculateTtl(array $response): int
    {
        // Use Cache-Control header if available
        if (isset($response['headers']['Cache-Control'])) {
            $cacheControl = $response['headers']['Cache-Control'];
            if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
                return (int) $matches[1];
            }
        }

        // Use Expires header if available
        if (isset($response['headers']['Expires'])) {
            $expires = strtotime($response['headers']['Expires']);
            if ($expires > time()) {
                return $expires - time();
            }
        }

        // Default TTL based on response characteristics
        $dataSize = strlen(serialize($response));

        if ($dataSize > 100 * 1024) { // Large responses
            return 7200; // 2 hours
        } elseif ($dataSize > 10 * 1024) { // Medium responses
            return 3600; // 1 hour
        } else { // Small responses
            return 1800; // 30 minutes
        }
    }
}
