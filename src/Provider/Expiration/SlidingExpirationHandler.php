<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider\Expiration;

/**
 * Handler for sliding expiration functionality.
 *
 * Responsible solely for managing sliding window expiration logic,
 * where cache item TTL is refreshed on each access.
 *
 * @author Walmir Silva <walmir.silva@kariricode.org>
 *
 * @see "Patterns of Enterprise Application Architecture" by Martin Fowler
 */
final readonly class SlidingExpirationHandler
{
    public function __construct(
        private TtlConverter $converter
    ) {
    }

    /**
     * Determines if sliding expiration should be applied.
     */
    public function shouldUpdate(\DateTimeInterface $currentExpiration, bool $slidingEnabled): bool
    {
        if (!$slidingEnabled) {
            return false;
        }

        // Don't update if already expired
        return $currentExpiration > new \DateTimeImmutable();
    }

    /**
     * Updates expiration time for sliding window.
     */
    public function updateExpiration(
        \DateTimeInterface $currentExpiration,
        int $slidingTtl,
        ?string $key = null,
        array $context = []
    ): \DateTimeInterface {
        if (!$this->shouldUpdate($currentExpiration, true)) {
            return $currentExpiration;
        }

        return $this->converter->fromSeconds($slidingTtl);
    }
}
