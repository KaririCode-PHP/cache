<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;

/**
 * Builder for ExpirationProvider using Builder Pattern.
 * Follows Single Responsibility Principle and provides fluent interface.
 */
final class ExpirationProviderBuilder
{
    private int $defaultTtl = 3600;
    private array $operationTtls = [];
    private array $keyPatternTtls = [];

    public function withDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;

        return $this;
    }

    public function withOperationTtl(string $operation, int $ttl): self
    {
        $this->operationTtls[$operation] = $ttl;

        return $this;
    }

    public function withKeyPatternTtl(string $pattern, int $ttl): self
    {
        $this->keyPatternTtls[$pattern] = $ttl;

        return $this;
    }

    public function withOperationTtls(array $ttls): self
    {
        $this->operationTtls = array_merge($this->operationTtls, $ttls);

        return $this;
    }

    public function withKeyPatternTtls(array $ttls): self
    {
        $this->keyPatternTtls = array_merge($this->keyPatternTtls, $ttls);

        return $this;
    }

    public function build(): ExpirationProvider
    {
        return new ConfigurableExpirationProvider(
            $this->defaultTtl,
            $this->operationTtls,
            $this->keyPatternTtls
        );
    }

    public static function fromConfig(array $config): ExpirationProvider
    {
        $builder = new self();

        if (isset($config['default_ttl'])) {
            $builder->withDefaultTtl($config['default_ttl']);
        }

        if (isset($config['operation_ttls'])) {
            $builder->withOperationTtls($config['operation_ttls']);
        }

        if (isset($config['key_pattern_ttls'])) {
            $builder->withKeyPatternTtls($config['key_pattern_ttls']);
        }

        return $builder->build();
    }
}
