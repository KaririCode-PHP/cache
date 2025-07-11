<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Expiration;

use KaririCode\Cache\Contract\Filesystem\ExpirationManager as ExpirationManagerContract;

/**
 * Expiration manager following SRP.
 * Responsible ONLY for expiration-related logic.
 */
final class ExpirationManager implements ExpirationManagerContract
{
    public function __construct(
        private float $cleanupProbability = 0.01
    ) {
    }

    public function isExpired(array $metadata): bool
    {
        if (!isset($metadata['expiration']) || null === $metadata['expiration']) {
            return false; // No expiration set
        }

        return $metadata['expiration'] < time();
    }

    public function createMetadata(mixed $value, ?\DateTimeInterface $expiration): array
    {
        return [
            'value' => $value,
            'expiration' => $expiration?->getTimestamp(),
            'created' => time(),
            'accessed' => time(),
            'size' => 0, // Will be set after serialization
        ];
    }

    public function shouldCleanup(): bool
    {
        return mt_rand() / mt_getrandmax() < $this->cleanupProbability;
    }

    /**
     * Update access time for cache item.
     */
    public function updateAccessTime(array &$metadata): void
    {
        $metadata['accessed'] = time();
    }

    /**
     * Calculate remaining TTL in seconds.
     */
    public function getRemainingTtl(array $metadata): ?int
    {
        if (!isset($metadata['expiration']) || null === $metadata['expiration']) {
            return null; // No expiration
        }

        $remaining = $metadata['expiration'] - time();

        return max(0, $remaining);
    }
}
