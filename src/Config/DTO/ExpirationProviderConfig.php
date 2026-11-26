<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that encapsulates configuration for the expiration provider.
 *
 * It defines default, operation-specific, and pattern-based Time-To-Live (TTL)
 * settings, as well as rules for sliding expiration and maximum TTL enforcement.
 */
final class ExpirationProviderConfig implements ImmutableValue
{
    /**
     * @param int $defaultTtl the default time-to-live in seconds for cache items
     * @param array<string, int> $operationTtls TTLs for specific cache operations (e.g., 'set', 'increment').
     * @param array<string, int> $keyPatternTtls TTLs for cache keys matching specific patterns (e.g., 'user:*').
     * @param bool $slidingExpiration whether to reset an item's TTL on each read access
     * @param int $maxTtl the maximum allowed TTL in seconds for any cache item
     */
    private function __construct(
        public readonly int $defaultTtl,
        public readonly array $operationTtls,
        public readonly array $keyPatternTtls,
        public readonly bool $slidingExpiration,
        public readonly int $maxTtl
    ) {
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config the raw configuration array
     */
    public static function fromConfig(array $config = []): self
    {
        return new self(
            defaultTtl: (int) ($config['default_ttl'] ?? ExpirationProvider::DEFAULT_TTL_SECONDS),
            operationTtls: (array) ($config['operation_ttls'] ?? []),
            keyPatternTtls: (array) ($config['key_pattern_ttls'] ?? []),
            slidingExpiration: (bool) ($config['sliding_expiration'] ?? false),
            maxTtl: (int) ($config['max_ttl'] ?? ExpirationProvider::MAX_TTL_SECONDS)
        );
    }
}
