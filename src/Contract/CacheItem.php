<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheItem
{
    public function getKey(): string;

    public function get(): mixed;

    public function isHit(): bool;

    public function set(mixed $value): static;

    public function expiresAt(?\DateTimeInterface $expiration): static;

    public function expiresAfter(\DateInterval|int|null $time): static;

    public function getExpiration(): ?\DateTimeInterface;
}
