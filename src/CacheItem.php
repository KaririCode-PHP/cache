<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\CacheItem as CacheItemContract;

/**
 * Enhanced cache item with tagging and topic subscription support.
 */
final class CacheItem implements CacheItemContract
{
    private string $key;
    private mixed $value;
    private bool $isHit;
    private ?\DateTimeInterface $expiration = null;
    private array $tags = [];
    private array $topics = [];

    public function __construct(string $key, mixed $value = null, bool $isHit = false)
    {
        $this->key = $key;
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
            $this->expiration = new \DateTime('+' . $time . ' seconds');
        }

        return $this;
    }

    public function getExpiration(): ?\DateTimeInterface
    {
        return $this->expiration;
    }

    public function tag(array $tags): static
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function subscribeTo(array $topics): static
    {
        $this->topics = array_merge($this->topics, $topics);

        return $this;
    }

    public function getTopics(): array
    {
        return $this->topics;
    }
}
