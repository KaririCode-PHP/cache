<?php

declare(strict_types=1);

namespace KaririCode\Cache\Item;

use KaririCode\Cache\Contract\CacheItem as CacheItemContract;

/**
 * A DTO representing a cache item, adhering to PSR-6.
 * This class is a simple data container; all validation is delegated to services.
 */
final class CacheItem implements CacheItemContract
{
    private ?\DateTimeInterface $expiration = null;

    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $isHit = false,
        private array $tags = []
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit && !$this->isExpired();
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
        } elseif (is_int($time)) {
            $this->expiration = (new \DateTimeImmutable())->modify("+{$time} seconds");
        } elseif ($time instanceof \DateInterval) {
            $this->expiration = (new \DateTimeImmutable())->add($time);
        }

        return $this;
    }

    public function getExpiration(): ?\DateTimeInterface
    {
        return $this->expiration;
    }

    public function tag(iterable $tags): static
    {
        foreach ($tags as $tag) {
            $this->tags[] = (string) $tag;
        }
        $this->tags = array_unique($this->tags);

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    private function isExpired(): bool
    {
        return null !== $this->expiration && $this->expiration < new \DateTimeImmutable();
    }
}
