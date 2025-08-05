<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;

/**
 * Default implementation using configuration-based TTL management.
 * Follows Strategy Pattern and Dependency Inversion Principle.
 */
final class ConfigurableExpirationProvider implements ExpirationProvider
{
    public function __construct(
        private readonly int $defaultTtl = 3600,
        private array $operationTtls = [],
        private array $keyPatternTtls = []
    ) {
        // Initialize with default TTL and optional operation-specific and key-pattern-specific TTLs.
        // This allows for flexible configuration of expiration times based on operations or key patterns.s
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    public function getTtlForOperation(string $operation): int
    {
        return $this->operationTtls[$operation] ?? $this->defaultTtl;
    }

    public function getTtlForKey(string $key): int
    {
        foreach ($this->keyPatternTtls as $pattern => $ttl) {
            if (fnmatch($pattern, $key)) {
                return $ttl;
            }
        }

        return $this->defaultTtl;
    }

    public function calculateExpiration(?int $ttl = null): ?\DateTimeInterface
    {
        if (null === $ttl) {
            $ttl = $this->defaultTtl;
        }

        if ($ttl <= 0) {
            return null;
        }

        return new \DateTimeImmutable("+{$ttl} seconds");
    }
}
