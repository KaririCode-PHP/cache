<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory\Adapter;

use KaririCode\Cache\Contract\Filesystem\ExpirationManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;

final class FilesystemExpirationAdapter implements ExpirationManager
{
    public function __construct(
        private ExpirationProvider $expirationProvider,
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

    public function createMetadata(mixed $value, ?\DateTimeInterface $expiration): array
    {
        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getDefaultTtl()
        );

        return [
            'value' => $value,
            'expiration' => $finalExpiration?->getTimestamp(),
            'created' => time(),
            'accessed' => time(),
            'size' => 0,
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
}
