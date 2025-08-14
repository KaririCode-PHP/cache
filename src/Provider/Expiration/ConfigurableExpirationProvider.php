<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider\Expiration;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;

/**
 * Refactored ConfigurableExpirationProvider following SOLID principles.
 *
 * Now serves as a coordinator/orchestrator, delegating specific responsibilities
 * to specialized classes. This follows the Single Responsibility Principle
 * and Dependency Inversion Principle.
 *
 * @author Walmir Silva <walmir.silva@kariricode.org>
 *
 * @see "Clean Architecture" by Robert C. Martin - Dependency Rule
 * @see "SOLID Principles" - https://www.digitalocean.com/community/conceptual_articles/s-o-l-i-d-the-first-five-principles-of-object-oriented-design
 */
final readonly class ConfigurableExpirationProvider implements ExpirationProvider
{
    public function __construct(
        private TtlConverter $converter,
        private TtlResolver $resolver,
        private SlidingExpirationHandler $slidingHandler,
        private TtlConstraintApplier $constraintApplier,
        private ExpirationProviderConfig $config
    ) {
    }

    /**
     * Factory method for easy instantiation with default dependencies.
     */
    public static function fromConfig(ExpirationProviderConfig $config): self
    {
        $converter = new TtlConverter();

        return new self(
            $converter,
            new TtlResolver($converter),
            new SlidingExpirationHandler($converter),
            new TtlConstraintApplier(),
            $config
        );
    }

    public function calculateExpiration(
        \DateInterval|\DateTimeInterface|int|null $ttl = null,
        ?string $key = null,
        ?string $operation = null,
        array $context = []
    ): ?\DateTimeInterface {
        $resolvedTtl = $this->resolver->resolveFromExplicit($ttl)
            ?? $this->resolver->resolveFromKeyPattern($key ?? '', $this->config->keyPatternTtls)
            ?? $this->resolver->resolveFromOperation($operation ?? '', $this->config->operationTtls)
            ?? $this->config->defaultTtl;

        // If no TTL is resolved to a value, return null to allow for permanent cache.
        if (null === $resolvedTtl) {
            return null;
        }

        $constrainedTtl = $this->constraintApplier->applyAllConstraints(
            $resolvedTtl,
            $this->config->maxTtl
        );

        return $this->converter->fromSeconds($constrainedTtl);
    }

    public function getDefaultTtl(): int
    {
        return $this->config->defaultTtl;
    }

    public function isExpired(?\DateTimeInterface $expiration): bool
    {
        // A null expiration means the item never expires, so it cannot be considered "expired".
        if (null === $expiration) {
            return false;
        }

        return $expiration < new \DateTimeImmutable();
    }

    public function updateSlidingExpiration(
        \DateTimeInterface $currentExpiration,
        ?string $key = null,
        array $context = []
    ): \DateTimeInterface {
        if (!$this->config->slidingExpiration || $this->isExpired($currentExpiration)) {
            return $currentExpiration;
        }

        // Use the item-specific TTL for sliding, not the global default, for consistent behavior.
        $ttlForSliding = $this->resolver->resolveFromKeyPattern($key ?? '', $this->config->keyPatternTtls)
            ?? $this->resolver->resolveFromOperation('get', $this->config->operationTtls)
            ?? $this->config->defaultTtl;

        return $this->slidingHandler->updateExpiration(
            $currentExpiration,
            $ttlForSliding,
            $key,
            $context
        );
    }

    public function isSlidingExpiration(): bool
    {
        return $this->config->slidingExpiration;
    }

    public function getConfiguration(): array
    {
        return [
            'default_ttl' => $this->config->defaultTtl,
            'operation_ttls' => $this->config->operationTtls,
            'key_pattern_ttls' => $this->config->keyPatternTtls,
            'sliding_expiration' => $this->config->slidingExpiration,
            'max_ttl' => $this->config->maxTtl,
        ];
    }

    public function toSeconds(\DateInterval|\DateTimeInterface|int|null $ttl): ?int
    {
        return $this->converter->toSeconds($ttl);
    }

    public function fromSeconds(int $seconds): \DateTimeInterface
    {
        return $this->converter->fromSeconds($seconds);
    }

    public function validateTtl(\DateInterval|\DateTimeInterface|int|null $ttl): bool
    {
        return $this->converter->isValid($ttl);
    }

    /**
     * Provides access to operation-specific TTL for backward compatibility.
     */
    public function getOperationTtl(string $operation): ?int
    {
        return $this->config->operationTtls[$operation] ?? null;
    }

    /**
     * Provides access to key pattern TTL for backward compatibility.
     */
    public function getKeyPatternTtl(string $key): ?int
    {
        return $this->resolver->resolveFromKeyPattern($key, $this->config->keyPatternTtls);
    }
}
