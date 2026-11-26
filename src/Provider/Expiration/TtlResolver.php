<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider\Expiration;

/**
 * Resolver responsible for determining TTL based on different strategies.
 *
 * Implements Strategy Pattern for TTL resolution with clear priority:
 * 1. Explicit TTL (highest priority)
 * 2. Key pattern matching
 * 3. Operation-specific TTL
 * 4. Default TTL (fallback)
 *
 * @author Walmir Silva <walmir.silva@kariricode.org>
 */
final readonly class TtlResolver
{
    public function __construct(
        private TtlConverter $converter
    ) {
    }

    /**
     * Resolves TTL from explicitly provided value.
     */
    public function resolveFromExplicit(\DateInterval|\DateTimeInterface|int|null $ttl): ?int
    {
        return null !== $ttl ? $this->converter->toSeconds($ttl) : null;
    }

    /**
     * Resolves TTL based on cache key patterns.
     *
     * @param string $key The cache key to match
     * @param array<string, int> $patterns Pattern-to-TTL mapping
     */
    public function resolveFromKeyPattern(string $key, array $patterns): ?int
    {
        foreach ($patterns as $pattern => $ttl) {
            if ($this->matchesPattern($key, $pattern)) {
                return $ttl;
            }
        }

        return null;
    }

    /**
     * Resolves TTL based on cache operation type.
     *
     * @param string $operation The cache operation (set, increment, etc.)
     * @param array<string, int> $operationTtls Operation-to-TTL mapping
     */
    public function resolveFromOperation(string $operation, array $operationTtls): ?int
    {
        return $operationTtls[$operation] ?? null;
    }

    /**
     * Returns the default TTL as final fallback.
     */
    public function resolveDefault(int $defaultTtl): int
    {
        return $defaultTtl;
    }

    /**
     * Checks if a key matches a given wildcard pattern.
     *
     * @param string $key The string to check (e.g., 'user:123').
     * @param string $pattern The wildcard pattern (e.g., 'user:*').
     *
     * @return bool true if the key matches the pattern
     */
    private function matchesPattern(string $key, string $pattern): bool
    {
        // fnmatch is the standard PHP function for shell-like wildcard matching.
        // It's simpler and more direct for this use case than a complex regex.
        return fnmatch($pattern, $key);
    }
}
