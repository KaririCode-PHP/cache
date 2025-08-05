<?php

declare(strict_types=1);

namespace KaririCode\Cache\Specialized;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Specialized cache for API responses with smart invalidation.
 */
final class ApiResponseCache
{
    private Cache $cache;
    private string $prefix;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(Cache $cache, string $prefix = 'api:', array $config = [])
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->config = array_merge([
            'default_ttl' => 3600,
            'max_response_size' => 1024 * 1024,
            'vary_on_headers' => ['Accept', 'Accept-Language'],
        ], $config);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|null
     */
    public function getResponse(string $endpoint, array $params = [], array $headers = []): ?array
    {
        $key = $this->generateKey($endpoint, $params, $headers);
        $cached = $this->cache->get($key);

        if (is_array($cached) && $this->isValidResponse($cached)) {
            return $cached;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params <-- FIX: Type updated to allow associative array
     * @param array<string, string> $headers
     * @param array<string, mixed> $response
     */
    public function cacheResponse(string $endpoint, array $params, array $headers, array $response, ?int $ttl = null): bool
    {
        if (!$this->shouldCacheResponse($response)) {
            return false;
        }

        $key = $this->generateKey($endpoint, $params, $headers);

        $cacheData = [
            'response' => $response,
            'cached_at' => time(),
            'endpoint' => $endpoint,
            'params' => $params,
            'headers' => $this->config['vary_on_headers'],
        ];

        $tagStrings = $this->generateTags($endpoint, $params);
        $tags = array_map(fn (string $tag) => new Tag($tag), $tagStrings);

        return $this->cache->setWithTags($key, $cacheData, $tags);
    }

    public function invalidateEndpoint(string $endpoint): bool
    {
        $tagString = $this->prefix . 'endpoint:' . hash('sha256', $endpoint);
        $tags = [new Tag($tagString)];

        return $this->cache->invalidateTags($tags);
    }

    /**
     * @param array<int, array<string, mixed>> $parameterSets
     *
     * @return array<string, array{success: bool, cached: bool, error?: string}>
     */
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
                    // FIX: Ensure the returned array shape is consistent on error.
                    $results[$key] = ['success' => false, 'cached' => false, 'error' => $e->getMessage()];
                }
            } else {
                $results[$key] = ['success' => true, 'cached' => false];
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $metrics = method_exists($this->cache, 'getMetrics') ? $this->cache->getMetrics()->toArray() : [];

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

        if (is_array($this->config['vary_on_headers'])) {
            foreach ($this->config['vary_on_headers'] as $header) {
                if (isset($headers[$header])) {
                    $keyParts[] = $header . ':' . $headers[$header];
                }
            }
        }

        return $this->prefix . hash('sha256', implode('|', $keyParts));
    }

    /**
     * @return string[]
     */
    private function generateTags(string $endpoint, array $params): array
    {
        $tags = [];
        $tags[] = $this->prefix . 'endpoint:' . hash('sha256', $endpoint);

        if (preg_match('/\/api\/v\d+\/([^\/]+)/', $endpoint, $matches)) {
            $tags[] = $this->prefix . 'resource:' . $matches[1];
        }
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
        if (isset($response['error']) || isset($response['errors'])) {
            return false;
        }

        $size = strlen(serialize($response));
        if ($size > ($this->config['max_response_size'] ?? 1048576)) {
            return false;
        }

        if (isset($response['headers']['Cache-Control'])) {
            $cacheControl = $response['headers']['Cache-Control'];
            if (is_string($cacheControl) && (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function isValidResponse(array $cached): bool
    {
        return isset($cached['response'], $cached['cached_at']);
    }
}
