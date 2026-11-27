<?php

declare(strict_types=1);

namespace KaririCode\Cache\Bridge;

use KaririCode\Cache\Clock\SystemClock;
use Psr\Cache\CacheItemInterface;
use Psr\Clock\ClockInterface;

/**
 * PSR-6 cache item: key, value, hit flag and expiration.
 *
 * Mutable and getter-based because PSR-6 mandates it (set(), expiresAt()
 * and expiresAfter() return the same instance; getKey()/get()/isHit()).
 * The Spec V4 getter ban does not apply to externally mandated interfaces.
 * resolveTtl() turns the expiration into seconds when the item is saved;
 * zero or less means "delete".
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): PSR types stay in Bridge/.
 * Spec V4: Entity (external contract).
 *
 * Preconditions: obtained from Psr6Bridge::getItem().
 * Thread-safety: mutable by the PSR-6 contract; confine to one request.
 *
 * Side effects: none; the pool persists it.
 *
 * Example:
 *     $item = $pool->getItem('k')->set($value)->expiresAfter(60);
 *
 * References: ADR-011.
 *
 * @see https://www.php-fig.org/psr/psr-6/#cacheiteminterface
 */
final class Psr6CacheItem implements CacheItemInterface
{
    private mixed $value;

    private ?\DateTimeImmutable $expiration = null;

    public function __construct(
        private readonly string $key,
        mixed $value = null,
        private readonly bool $isHit = false,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->value = $value;
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->value;
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->isHit;
    }

    #[\Override]
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    #[\Override]
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiration = null === $expiration ? null : \DateTimeImmutable::createFromInterface($expiration);

        return $this;
    }

    /**
     * PSR-6: the period starts now, when the method is called, not when the
     * item is saved; a deferred item committed later keeps its deadline.
     */
    #[\Override]
    public function expiresAfter(\DateInterval|int|null $time): static
    {
        $now = $this->clock->now();

        $this->expiration = match (true) {
            null === $time => null,
            $time instanceof \DateInterval => $now->add($time),
            default => $now->setTimestamp($now->getTimestamp() + $time),
        };

        return $this;
    }

    /**
     * Seconds left at $now; zero or less means expired, null means no expiry.
     *
     * @internal Used by Psr6Bridge.
     */
    public function resolveTtl(\DateTimeImmutable $now): ?int
    {
        return null === $this->expiration ? null : $this->expiration->getTimestamp() - $now->getTimestamp();
    }
}
