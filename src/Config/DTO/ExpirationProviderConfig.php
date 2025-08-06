<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Expiration Provider Configuration DTO.
 */
final class ExpirationProviderConfig implements ImmutableValue
{
    public readonly int $defaultTtl;
    public readonly array $operationTtls;
    public readonly array $keyPatternTtls;
    public readonly bool $slidingExpiration;
    public readonly int $maxTtl;

    public function __construct(array $config = [])
    {
        $this->defaultTtl = $config['default_ttl'] ?? 3600;
        $this->operationTtls = $config['operation_ttls'] ?? [];
        $this->keyPatternTtls = $config['key_pattern_ttls'] ?? [];
        $this->slidingExpiration = $config['sliding_expiration'] ?? false;
        $this->maxTtl = $config['max_ttl'] ?? 2592000; // 30 days
    }

    public function toArray(): array
    {
        return [
            'default_ttl' => $this->defaultTtl,
            'operation_ttls' => $this->operationTtls,
            'key_pattern_ttls' => $this->keyPatternTtls,
            'sliding_expiration' => $this->slidingExpiration,
            'max_ttl' => $this->maxTtl,
        ];
    }
}
