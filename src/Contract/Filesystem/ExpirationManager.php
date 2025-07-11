<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

interface ExpirationManager
{
    public function isExpired(array $metadata): bool;

    public function createMetadata(mixed $value, ?\DateTimeInterface $expiration): array;

    public function shouldCleanup(): bool;

    public function updateAccessTime(array $metadata): void;
}
