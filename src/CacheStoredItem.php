<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheItem;

class CacheStoredItem implements CacheItem
{
    private mixed $value;
    private bool $isHit;
    private ?\DateTimeInterface $expiration = null;

    public function __construct(
        private readonly string $key,
        mixed $value = null,
        bool $isHit = false
    ) {
        $this->value = $value;
        $this->isHit = $isHit;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;

        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if (null === $time) {
            $this->expiration = null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiration = (new \DateTime())->add($time);
        } elseif (is_int($time)) {
            $this->expiration = (new \DateTime())->add(new \DateInterval("PT{$time}S"));
        }

        return $this;
    }

    public function getExpiration(): ?\DateTimeInterface
    {
        return $this->expiration;
    }
}
