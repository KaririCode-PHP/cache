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
        if (!isset($metadata['expiration'])) {
            return false;
        }

        return $metadata['expiration'] < time();
    }

    /**
     * @return array{value: mixed, expiration: int|null, created: int, accessed: int, size: int}
     */
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

    public function updateAccessTime(array &$metadata): void
    {
        $metadata['accessed'] = time();
    }

    public function getRemainingTtl(array $metadata): ?int
    {
        if (!isset($metadata['expiration'])) {
            return null;
        }

        $remaining = (int) $metadata['expiration'] - time();

        return max(0, $remaining);
    }
}
